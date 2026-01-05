<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileMissing extends AbstractCheckFile
{
    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate
        'hash' => 'Hash', // @translate
        'type' => 'Type', // @translate
        'exists' => 'Exists', // @translate
        'source' => 'Source', // @translate
        'fixed' => 'Fixed', // @translate
        'message' => 'Message', // @translate
    ];

    /**
     * @var string
     */
    protected $sourceDir;

    /**
     * @var array
     */
    protected $files;

    /**
     * @var array
     */
    protected $extensions = [];

    /**
     * @var array
     */
    protected $extensionsExclude = [];

    /**
     * @var array
     */
    protected $filenamesEndExclude = [];

    /**
     * @var string
     */
    protected $matchingMode;

    /**
     * @var bool
     */
    protected $reportFull = false;

    public function perform(): void
    {
        // Memory optimization: yield + md5 binary keys reduce memory usage.
        // Uncomment if needed for very large file sets (4M+ files).
        // ini_set('memory_limit', '3G');

        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->extensions = $this->getArg('extensions', []);
        if (!is_array($this->extensions)) {
            $this->extensions = explode(',', $this->extensions);
        }
        $this->extensions = array_unique(array_filter(array_map('trim', $this->extensions), 'strlen'));

        $this->extensionsExclude = $this->getArg('extensions_exclude', []);
        if (!is_array($this->extensionsExclude)) {
            $this->extensionsExclude = explode(',', $this->extensionsExclude);
        }
        $this->extensionsExclude = array_unique(array_filter(array_map('trim', $this->extensionsExclude), 'strlen'));

        $this->filenamesEndExclude = $this->getArg('filenames_end_exclude', []);
        if (!is_array($this->filenamesEndExclude)) {
            $this->filenamesEndExclude = explode(',', $this->filenamesEndExclude);
        }
        $this->filenamesEndExclude = array_unique(array_filter(array_map('trim', $this->filenamesEndExclude), 'strlen'));

        $matchingModes = [
            'sha256',
            'md5',
            'source',
            'source_filename',
        ];
        $this->matchingMode = $this->getArg('matching') ?: null;

        // Require matching mode for all files_missing processes.
        if (empty($this->matchingMode)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Matching mode is required for process "{process}".', // @translate
                ['process' => $process]
            );
            $this->finalizeOutput();
            return;
        }

        if (!in_array($this->matchingMode, $matchingModes)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Matching mode "{mode}" is not supported.', // @translate
                ['mode' => $this->matchingMode]
            );
            $this->finalizeOutput();
            return;
        }

        // Report type: 'full' lists all files, 'partial' lists only missing.
        $this->reportFull = $this->getArg('report_type') === 'full';

        if ($process === 'files_missing_fix') {
            $this->prepareSourceDirectory();
            if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
                return;
            }
        }

        $fix = in_array($process, ['files_missing_fix', 'files_missing_fix_db'])
            ? $process
            : false;

        // Don't include derivative during fix, neither for files or database.
        // Do not remove media from database if only a derivative is missing!
        $includeDerivatives = !$fix && $this->getArg('include_derivatives', false);

        $this->checkMissingFiles($fix, ['include_derivatives' => $includeDerivatives]);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();

        if ($fix && $this->matchingMode === 'md5') {
            $this->logger->warn(
                'The source files are not hashed with sha256, so they must be updated with the right task.' // @translate
            );
        }

        if ($process === 'files_missing_fix') {
            $this->logger->warn(
                'The derivative files are not rebuilt automatically. Check them and recreate them via the other processes.' // @translate
            );
        }
    }

    protected function prepareSourceDirectory(): self
    {
        $dir = rtrim($this->getArg('source_dir'), '/');
        if (!$dir || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Source directory "{path}" is not set or not readable.', // @translate
                ['path' => $dir]
            );
            return $this;
        }

        if (realpath($dir) !== $dir || strlen($dir) <= 1) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Source directory "{path}" should be a real path ({realpath}).', // @translate
                ['path' => $dir, 'realpath' => realpath($dir)]
            );
            return $this;
        }

        $this->sourceDir = $dir;

        // Prepare a list of hash of all files one time.
        // Use generator (yield) to avoid loading all files in memory.
        if (in_array($this->matchingMode, ['sha256', 'md5'])) {
            $this->logger->info(
                'Preparing hashes of files (this may take a long time).' // @translate
            );
        } else {
            $this->logger->info(
                'Preparing list of files.' // @translate
            );
        }

        // File is a relative path, filepath the absolute one.
        // Memory optimization: use generator to iterate files without
        // loading all in memory.
        $filesIterator = $this->iterateFilesInFolder($dir, false, $this->extensions, $this->extensionsExclude, $this->filenamesEndExclude);

        if ($this->matchingMode === 'source_filename') {
            // Build index by filename using md5 binary hash as key
            // (16 bytes vs ~50 chars).
            // Track duplicates separately to report them without storing
            // all paths.
            $result = [];
            $duplicates = [];
            $count = 0;
            foreach ($filesIterator as $file) {
                $filepath = $dir . '/' . $file;
                $extension = pathinfo($filepath, PATHINFO_EXTENSION);
                if (!$extension) {
                    $this->logger->warn(
                        'Source file "{path}" has no extension.', // @translate
                        ['path' => $file]
                    );
                }
                if (is_readable($filepath)) {
                    $filename = pathinfo($filepath, PATHINFO_BASENAME);
                    $filenameHash = md5($filename, true);
                    if (isset($result[$filenameHash])) {
                        // Track duplicate count, not all paths.
                        $duplicates[$filename] = ($duplicates[$filename] ?? 1) + 1;
                    } else {
                        $result[$filenameHash] = $file;
                    }
                } else {
                    $this->logger->warn(
                        'Source file "{path}" is not readable.', // @translate
                        ['path' => $file]
                    );
                }

                ++$count;
                if ($count % 10000 === 0) {
                    $this->logger->info(
                        '{count} elements prepared.', // @translate
                        ['count' => $count]
                    );
                }
            }

            // Report duplicates.
            if (count($duplicates)) {
                $this->logger->warn(
                    'This following flles have duplicate names: {json}', // @translate
                    ['json' => json_encode($duplicates, 448)]
                );
            }

            $this->files = $result;
            unset($result, $duplicates);
        } else {
            // For sha256, md5, source modes.
            $result = [];
            $count = 0;
            foreach ($filesIterator as $file) {
                $filepath = $dir . '/' . $file;
                $extension = pathinfo($filepath, PATHINFO_EXTENSION);
                if (!$extension) {
                    $this->logger->warn(
                        'Source file "{path}" has no extension.', // @translate
                        ['path' => $file]
                    );
                }
                if (is_readable($filepath)) {
                    switch ($this->matchingMode) {
                        default:
                        case 'sha256':
                            $result[hash_file('sha256', $filepath)] = $file;
                            break;
                        case 'md5':
                            $result[md5_file($filepath)] = $file;
                            break;
                        case 'source':
                            $result[$file] = $file;
                            break;
                    }
                } else {
                    $this->logger->warn(
                        'Source file "{path}" is not readable.', // @translate
                        ['path' => $file]
                    );
                }

                ++$count;
                // Log every 100 for slow hash processes.
                if ($count % 100 === 0
                    && in_array($this->matchingMode, ['sha256', 'md5'])
                ) {
                    $this->logger->info(
                        '{count} hashes prepared.', // @translate
                        ['count' => $count]
                    );
                } elseif ($count % 10000 === 0) {
                    $this->logger->info(
                        '{count} elements prepared.', // @translate
                        ['count' => $count]
                    );
                }
            }

            $this->files = $result;
            unset($result);
        }

        if (!count($this->files)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Source directory "{path}" is empty.', // @translate
                ['path' => $dir]
            );
            return $this;
        }

        $this->logger->notice(
            'The source directory contains {total} readable files.', // @translate
            ['total' => count($this->files)]
        );
        if ($count !== count($this->files)) {
            $this->logger->notice(
                'The source directory contains {total} duplicate files.', // @translate
                ['total' => $count - count($this->files)]
            );
        }

        return $this;
    }

    /**
     * @param string|bool $fix
     * @param array $options
     * @return bool
     */
    protected function checkMissingFiles($fix = false, array $options = []): bool
    {
        $result = $this->checkMissingFilesForTypes(['original'], $fix);
        if (!$result) {
            return false;
        }

        // Do not remove media from database if only a derivative is missing!
        if (!$fix && !empty($options['include_derivatives'])) {
            $result = $this->checkMissingFilesForTypes(array_keys($this->config['thumbnails']['types']));
        }

        return $result;
    }

    /**
     * @param array $types
     * @param string|bool $fix
     * @return bool
     */
    protected function checkMissingFilesForTypes(array $types, $fix = false): bool
    {
        // In big recoveries, it is recommended to clear the caches.
        $this->entityManager->clear();

        $isOriginalMain = array_values($types) === ['original'];
        if ($isOriginalMain) {
            $sql = 'SELECT COUNT(id) FROM media WHERE has_original = 1';
            $totalToProcess = $this->connection->executeQuery($sql)->fetchOne();
            $this->logger->notice(
                'Checking {total} media with original files.', // @translate
                ['total' => $totalToProcess]
            );
        } else {
            $sql = 'SELECT COUNT(id) FROM media WHERE has_thumbnails = 1';
            $totalToProcess = $this->connection->executeQuery($sql)->fetchOne();
            $this->logger->notice(
                'Checking {total} media with thumbnails.', // @translate
                ['total' => $totalToProcess]
            );
        }

        if (empty($totalToProcess)) {
            $this->logger->notice(
                'No media to process.' // @translate
            );
            return true;
        }

        // First, list files indexed by filename for O(1) lookup.
        $types = array_flip($types);
        foreach (array_keys($types) as $type) {
            $path = $this->basePath . '/' . $type;
            $files = $type === 'original'
                // Here, it is useless to use the arg to exclude filenames.
                ? $this->listFilesInFolder($path, false, $this->extensions, $this->extensionsExclude)
                : $this->listFilesInFolder($path);
            // Use filename as key for O(1) isset() instead of O(n) in_array().
            $types[$type] = array_flip($files);
            unset($files);
        }

        $fixDb = $fix === 'files_missing_fix_db';

        // Use entities only for fixDb (need to delete), else use faster SQL.
        if ($fixDb) {
            return $this->checkMissingFilesWithEntities($types, $totalToProcess, $isOriginalMain);
        }

        return $this->checkMissingFilesWithSql($types, $fix, $totalToProcess, $isOriginalMain);
    }

    /**
     * Check missing files using raw SQL (faster, for check and fix files).
     */
    protected function checkMissingFilesWithSql(array $types, $fix, int $totalToProcess, bool $isOriginalMain): bool
    {
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate
        $noSource = $translator->translate('No source'); // @translate
        $copyIssue = $translator->translate('Copy issue'); // @translate
        $alreadyExists = $translator->translate('Already exists'); // @translate

        // Use larger batch for scalar queries (no entity overhead).
        $batchSize = self::SQL_LIMIT_LARGE;

        // Build SQL query for required columns only.
        $sqlBase = 'SELECT m.id, m.item_id, m.storage_id, m.extension, m.sha256, m.source
            FROM media m WHERE ';
        $sqlBase .= $isOriginalMain ? 'm.has_original = 1' : 'm.has_thumbnails = 1';
        $sqlBase .= ' ORDER BY m.id ASC LIMIT :limit OFFSET :offset';

        $offset = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        $totalSkipped = 0;

        // Cache for created directories to avoid repeated file_exists() calls.
        $createdDirs = [];

        // Check if source and destination are on same filesystem for hardlink.
        $canHardlink = $fix && $this->sourceDir
            && $this->canUseHardlink($this->sourceDir, $this->basePath);

        while (true) {
            $rows = $this->connection->executeQuery(
                $sqlBase,
                ['limit' => (int) $batchSize, 'offset' => (int) $offset],
                ['limit' => \PDO::PARAM_INT, 'offset' => \PDO::PARAM_INT]
            )->fetchAllAssociative();
            if (!count($rows) || $totalProcessed >= $totalToProcess) {
                break;
            }

            if ($this->shouldStop()) {
                $this->logger->notice(
                    'Job stopped: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed ({mode}).', // @translate
                    [
                        'processed' => $totalProcessed,
                        'total' => $totalToProcess,
                        'total_succeed' => $totalSucceed,
                        'total_failed' => $totalFailed,
                        'mode' => $isOriginalMain ? 'original' : sprintf('%d thumbnails', count($types)),
                    ]
                );
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return false;
            }

            if ($totalProcessed) {
                $this->logger->info(
                    '{processed}/{total} media processed.', // @translate
                    ['processed' => $totalProcessed, 'total' => $totalToProcess]
                );
            }

            foreach ($rows as $mediaRow) {
                $mediaId = (int) $mediaRow['id'];
                $itemId = (int) $mediaRow['item_id'];
                $storageId = $mediaRow['storage_id'];
                $extension = $mediaRow['extension'];
                $sha256 = $mediaRow['sha256'];
                $source = $mediaRow['source'];

                foreach ($types as $type => $files) {
                    $isOriginal = $type === 'original';
                    $filename = $isOriginal
                        ? ($extension ? $storageId . '.' . $extension : $storageId)
                        : ($storageId . '.jpg');

                    // File exists in destination: skip writing unless full report.
                    if (isset($files[$filename])) {
                        ++$totalSucceed;
                        // Write row only for full report mode.
                        if ($this->reportFull) {
                            $this->writeRow([
                                'item' => $itemId,
                                'media' => $mediaId,
                                'filename' => $filename,
                                'extension' => $isOriginal ? $extension : 'jpg',
                                'hash' => $sha256,
                                'type' => $type,
                                'exists' => $yes,
                                'source' => '',
                                'fixed' => '',
                                'message' => '',
                            ]);
                        }
                        continue;
                    }

                    // File missing: need to fix or report.
                    $row = [
                        'item' => $itemId,
                        'media' => $mediaId,
                        'filename' => $filename,
                        'extension' => $isOriginal ? $extension : 'jpg',
                        'hash' => $sha256,
                        'type' => $type,
                        'exists' => $no,
                        'source' => '',
                        'fixed' => '',
                        'message' => '',
                    ];

                    if (!$fix) {
                        $row['fixed'] = $noSource;
                        ++$totalFailed;
                        $this->writeRow($row);
                        continue;
                    }

                    // Fix mode: try to restore the file.
                    if (!$isOriginal) {
                        $row['fixed'] = $no;
                        $this->writeRow($row);
                        break;
                    }

                    switch ($this->matchingMode) {
                        default:
                        case 'sha256':
                            $hash = $sha256;
                            break;
                        case 'md5':
                            // Fake sha256, to be updated.
                            $hash = $sha256;
                            break;
                        case 'source':
                            $hash = ltrim($source, '/');
                            break;
                        case 'source_filename':
                            // Use md5 binary hash to match the index.
                            $hash = md5(pathinfo($source, PATHINFO_BASENAME), true);
                            break;
                    }

                    if (!isset($this->files[$hash])) {
                        $row['source'] = $no;
                        $row['fixed'] = $noSource;
                        ++$totalFailed;
                        $this->writeRow($row);
                        continue;
                    }

                    $row['source'] = $this->sourceDir . '/' . $this->files[$hash];
                    $src = $this->sourceDir . '/' . $this->files[$hash];
                    $dest = $this->basePath . '/original/' . $filename;

                    // Skip if destination already exists (resume support).
                    if (file_exists($dest)) {
                        $row['fixed'] = $alreadyExists;
                        ++$totalSkipped;
                        $this->writeRow($row);
                        continue;
                    }

                    // Create directory if needed (with cache).
                    $destDir = dirname($dest);
                    if (!isset($createdDirs[$destDir])) {
                        if (!file_exists($destDir)) {
                            $result = @mkdir($destDir, 0755, true);
                            if (!$result) {
                                $row['fixed'] = $copyIssue;
                                $row['message'] = error_get_last()['message'] ?? '';
                                ++$totalFailed;
                                $this->writeRow($row);
                                continue;
                            }
                        }
                        $createdDirs[$destDir] = true;
                    }

                    // Try hardlink first (fast), fallback to copy.
                    if ($canHardlink) {
                        $result = @link($src, $dest);
                    } else {
                        $result = false;
                    }
                    if (!$result) {
                        $result = @copy($src, $dest);
                    }

                    if ($result) {
                        $row['fixed'] = $yes;
                        ++$totalSucceed;
                    } else {
                        $row['fixed'] = $copyIssue;
                        $row['message'] = error_get_last()['message'] ?? '';
                        ++$totalFailed;
                    }
                    $this->writeRow($row);
                }

                ++$totalProcessed;
            }

            unset($rows);
            $offset += $batchSize;
        }

        $message = $totalSkipped
            ? 'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed, {total_skipped} skipped ({mode}).' // @translate
            : 'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed ({mode}).'; // @translate
        $this->logger->notice($message, [
            'processed' => $totalProcessed,
            'total' => $totalToProcess,
            'total_succeed' => $totalSucceed,
            'total_failed' => $totalFailed,
            'total_skipped' => $totalSkipped,
            'mode' => $isOriginalMain ? 'original' : sprintf('%d thumbnails', count($types)),
        ]);

        return true;
    }

    /**
     * Check if hardlink can be used between source and destination.
     */
    protected function canUseHardlink(string $src, string $dest): bool
    {
        // Hardlinks only work on same filesystem.
        // Compare device IDs of source and destination directories.
        $srcStat = @stat($src);
        $destStat = @stat($dest);
        if (!$srcStat || !$destStat) {
            return false;
        }
        return $srcStat['dev'] === $destStat['dev'];
    }

    /**
     * Check missing files using Doctrine entities (for fixDb mode).
     */
    protected function checkMissingFilesWithEntities(array $types, int $totalToProcess, bool $isOriginalMain): bool
    {
        $criteria = new \Doctrine\Common\Collections\Criteria();
        $expr = $criteria->expr();

        if ($isOriginalMain) {
            $criteria->where($expr->eq('hasOriginal', 1));
        } else {
            $criteria->where($expr->eq('hasThumbnails', 1));
        }

        $criteria
            ->orderBy(['id' => 'ASC'])
            ->setFirstResult(null)
            ->setMaxResults(self::SQL_LIMIT);

        $baseCriteria = $criteria;

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate
        $noSource = $translator->translate('No source'); // @translate
        $itemRemoved = $translator->translate('Item removed'); // @translate
        $mediaRemoved = $translator->translate('Media removed'); // @translate

        // Since the fixed medias are no more available in the database, the
        // loop should take care of them, so a check is done on it.
        $lastId = 0;

        $offset = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        $totalFixed = 0;

        while (true) {
            $criteria = clone $baseCriteria;
            $criteria->andWhere($expr->gt('id', $lastId));
            $medias = $this->mediaRepository->matching($criteria);
            if (!$medias->count() || $offset >= $totalToProcess || $totalProcessed >= $totalToProcess) {
                break;
            }

            if ($this->shouldStop()) {
                $this->logger->notice(
                    'Job stopped: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed, {total_fixed} fixed.', // @translate
                    [
                        'processed' => $totalProcessed,
                        'total' => $totalToProcess,
                        'total_succeed' => $totalSucceed,
                        'total_failed' => $totalFailed,
                        'total_fixed' => $totalFixed,
                    ]
                );
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return false;
            }

            if ($totalProcessed) {
                $this->logger->info(
                    '{processed}/{total} media processed.', // @translate
                    ['processed' => $totalProcessed, 'total' => $totalToProcess]
                );
            }

            /** @var \Omeka\Entity\Media $media */
            foreach ($medias as $media) {
                $lastId = $media->getId();
                $item = $media->getItem();
                $itemId = $item->getId();
                foreach ($types as $type => $files) {
                    $isOriginal = $type === 'original';
                    $filename = $isOriginal
                        ? $media->getFilename()
                        : ($media->getStorageId() . '.jpg');
                    $row = [
                        'item' => $itemId,
                        'media' => $media->getId(),
                        'filename' => $filename,
                        'extension' => pathinfo($filename, PATHINFO_EXTENSION),
                        'hash' => $media->getSha256(),
                        'type' => $type,
                        'exists' => '',
                        'source' => '',
                        'fixed' => '',
                        'message' => '',
                    ];
                    if (isset($files[$filename])) {
                        $row['exists'] = $yes;
                        ++$totalSucceed;
                    } else {
                        $row['exists'] = $no;
                        // Remove item if it has only one media, otherwise
                        // remove just the media.
                        if ($item->getMedia()->count() === 1) {
                            $this->entityManager->remove($item);
                            $this->entityManager->flush();
                            $row['fixed'] = $itemRemoved;
                        } else {
                            $this->entityManager->remove($media);
                            $this->entityManager->flush();
                            $row['fixed'] = $mediaRemoved;
                        }
                        ++$totalFixed;
                        ++$totalFailed;
                    }
                    $this->writeRow($row);
                }

                ++$totalProcessed;

                // Avoid memory issue.
                unset($media);
            }

            // Avoid memory issue.
            unset($medias);
            $this->entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed, {total_fixed} fixed.', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
                'total_succeed' => $totalSucceed,
                'total_failed' => $totalFailed,
                'total_fixed' => $totalFixed,
            ]
        );

        return true;
    }
}

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

    public function perform(): void
    {
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
        $this->matchingMode = $this->getArg('matching', 'sha256') ?: 'sha256';
        if (!in_array($this->matchingMode, $matchingModes)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Matching mode "{mode}" is not supported.', // @translate
                ['mode' => $this->matchingMode]
            );
            $this->finalizeOutput();
            return;
        }

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

        $this->files = $this->listFilesInFolder($dir, false, $this->extensions, $this->extensionsExclude, $this->filenamesEndExclude);
        if (!count($this->files)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Source directory "{path}" is empty.', // @translate
                ['path' => $dir]
            );
            return $this;
        }

        $this->sourceDir = $dir;

        $total = count($this->files);

        // Prepare a list of hash of all files one time.
        $this->logger->info(
            'Preparing hashes of {total} files (this may take a long time).', // @translate
            ['total' => $total]
        );

        // File is a relative path, filepath the absolute one.
        $result = [];
        $count = 0;
        foreach ($this->files as $file) {
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
                    case 'source_filename':
                        // Use an array to do a check for duplicates below.
                        $filename = pathinfo($filepath, PATHINFO_BASENAME);
                        $result[$filename][] = $file;
                        break;
                }
            } else {
                $this->logger->warn(
                    'Source file "{path}" is not readable.', // @translate
                    ['path' => $file]
                );
            }

            ++$count;
            if ($count % 100 === 0
                // The other processes are instant, so no need to log loop.
                && in_array($this->matchingMode, ['sha256', 'md5'])
            ) {
                $this->logger->info(
                    '{count}/{total} hashes prepared.', // @translate
                    [
                        'count' => $count,
                        'total' => $total,
                    ]
                );
            }
        }

        // Check for duplicates for information.
        // This check is useful only for source_filename, since for other ones,
        // it's not an issue.
        if ($this->matchingMode === 'source_filename') {
            $duplicates = array_filter(array_map(fn ($v) => count($v), $result), fn ($v) => $v > 1);
            if (count($duplicates)) {
                $this->logger->warn(
                    'This following flles have duplicate names: {json}', // @translate
                    ['json' => json_encode($duplicates, 448)]
                );
            }
            // Take the first file path.
            $result = array_map(fn ($v) => reset($v), $result);
        }

        $this->files = $result;

        $this->logger->notice(
            'The source directory contains {total} readable files.', // @translate
            ['total' => count($this->files)]
        );
        if ($total !== count($this->files)) {
            $this->logger->notice(
                'The source directory contains {total} duplicate files.', // @translate
                ['total' => $total - count($this->files)]
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

        // Entity are used, because it's not possible to get the value
        // "has_original" or "has_thumbnails" via api.
        $criteria = new \Doctrine\Common\Collections\Criteria();
        $expr = $criteria->expr();

        $isOriginalMain = array_values($types) === ['original'];
        if ($isOriginalMain) {
            $criteria->where($expr->eq('hasOriginal', 1));
            $sql = 'SELECT COUNT(id) FROM media WHERE has_original = 1';
            $totalToProcess = $this->connection->executeQuery($sql)->fetchOne();
            $this->logger->notice(
                'Checking {total} media with original files.', // @translate
                ['total' => $totalToProcess]
            );
        } else {
            $criteria->where($expr->eq('hasThumbnails', 1));
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

        $criteria
            ->orderBy(['id' => 'ASC'])
            ->setFirstResult(null)
            ->setMaxResults(self::SQL_LIMIT);

        // First, list files.
        $types = array_flip($types);
        foreach (array_keys($types) as $type) {
            $path = $this->basePath . '/' . $type;
            $types[$type] = $type === 'original'
                // Here, it is useless to use the arg to exclude filenames.
                ? $this->listFilesInFolder($path, false, $this->extensions, $this->extensionsExclude)
                : $this->listFilesInFolder($path);
        }

        $fixDb = $fix === 'files_missing_fix_db';

        $baseCriteria = $criteria;

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate
        $noSource = $translator->translate('No source'); // @translate
        $copyIssue = $translator->translate('Copy issue'); // @translate
        $itemRemoved = $translator->translate('Item removed'); // @translate
        $itemNotRemoved = $translator->translate('Item not removed: more than one media'); // @translate

        // Since the fixed medias are no more available in the database, the
        // loop should take care of them, so a check is done on it.
        $lastId = 0;

        // Second, loop all media data.
        $offset = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        $totalFixed = 0;
        while (true) {
            $criteria = clone $baseCriteria;
            if ($fixDb) {
                $criteria
                    // Don't use offset, since last id is used, because some ids
                    // may have been removed.
                    ->andWhere($expr->gt('id', $lastId));
                $medias = $this->mediaRepository->matching($criteria);
                if (!$medias->count() || $offset >= $totalToProcess || $totalProcessed >= $totalToProcess) {
                    break;
                }
            } else {
                $criteria
                    ->setFirstResult($offset);
                $medias = $this->mediaRepository->matching($criteria);
                // if (!$medias->count()) {
                if (!$medias->count() || $totalProcessed >= $totalToProcess) {
                    break;
                }
            }

            if ($this->shouldStop()) {
                if ($fixDb) {
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
                } else {
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
                }
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
                    if (in_array($filename, $files)) {
                        $row['exists'] = $yes;
                        ++$totalSucceed;
                    } elseif ($fix) {
                        $row['exists'] = $no;
                        if ($fixDb) {
                            // TODO Fix items with more than one missing file.
                            if ($item->getMedia()->count() === 1) {
                                $this->entityManager->remove($item);
                                $this->entityManager->flush();
                                $row['fixed'] = $itemRemoved;
                                ++$totalFixed;
                            } else {
                                $row['fixed'] = $itemNotRemoved;
                            }
                        } else {
                            if (!$isOriginal) {
                                $row['fixed'] = $no;
                                $this->writeRow($row);
                                break;
                            }
                            switch ($this->matchingMode) {
                                default:
                                case 'sha256':
                                    $hash = $media->getSha256();
                                    break;
                                case 'md5':
                                    // This is a fake sha256 in the table, so to be updated next.
                                    $hash = $media->getSha256();
                                    break;
                                case 'source':
                                    $hash = ltrim($media->getSource(), '/');
                                    break;
                                case 'source_filename':
                                    $hash = pathinfo($media->getSource(), PATHINFO_BASENAME);
                                    break;
                            }
                            if (isset($this->files[$hash])) {
                                $row['source'] = $this->sourceDir . '/' . $this->files[$hash];
                                $src = $this->sourceDir . '/' . $this->files[$hash];
                                $dest = $this->basePath . '/original/' . $filename;
                                $hasCopyError = false;
                                if (!file_exists(dirname($dest))) {
                                    // Create folder for Archive Repertory.
                                    $result = mkdir(dirname($dest), 0755, true);
                                    if (!$result) {
                                        $row['fixed'] = $copyIssue;
                                        $row['message'] = error_get_last()['message'];
                                        ++$totalFailed;
                                        $hasCopyError = true;
                                    }
                                }
                                if (!$hasCopyError) {
                                    $result = copy($src, $dest);
                                    if ($result) {
                                        $row['fixed'] = $yes;
                                        ++$totalSucceed;
                                    } else {
                                        $row['fixed'] = $copyIssue;
                                        $row['message'] = error_get_last()['message'];
                                        ++$totalFailed;
                                    }
                                }
                            } else {
                                $row['source'] = $no;
                                $row['fixed'] = $noSource;
                                ++$totalFailed;
                            }
                        }
                    } else {
                        $row['exists'] = $no;
                        $row['source'] = $no;
                        $row['fixed'] = $noSource;
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

        if ($fixDb) {
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
        } else {
            $this->logger->notice(
                'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed ({mode}).', // @translate
                [
                    'processed' => $totalProcessed,
                    'total' => $totalToProcess,
                    'total_succeed' => $totalSucceed,
                    'total_failed' => $totalFailed,
                    'mode' => $isOriginalMain ? 'original' : sprintf('%d thumbnails', count($types)),
                ]
            );
        }

        return true;
    }
}

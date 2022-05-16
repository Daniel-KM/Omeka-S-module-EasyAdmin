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

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->extensions = $this->getArg('extensions', '');
        $this->extensions = array_unique(array_filter(array_map('trim', explode(',', $this->extensions)), 'strlen'));

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

        if ($process === 'files_missing_fix') {
            $this->logger->warn(
                'The derivative files are not rebuilt automatically. Check them and recreate them via the other processes.' // @translate
            );
        }
    }

    /**
     * @return self
     */
    protected function prepareSourceDirectory()
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

        $this->files = $this->listFilesInFolder($dir, false, $this->extensions);
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

        $count = 0;
        foreach ($this->files as $key => $file) {
            $filepath = $dir . '/' . $file;
            if (is_readable($filepath)) {
                $this->files[hash_file('sha256', $filepath)] = $file;
            } else {
                $this->logger->warn(
                    'Source file "{path}" is not readable.', // @translate
                    ['path' => $file]
                );
            }
            unset($this->files[$key]);

            ++$count;
            if ($count % 100 === 0) {
                $this->logger->info(
                    '{count}/{total} hashes prepared.', // @translate
                    [
                        'count' => $count,
                        'total' => $total,
                    ]
                );
            }
        }

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
    protected function checkMissingFiles($fix = false, array $options)
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
    protected function checkMissingFilesForTypes(array $types, $fix = false)
    {
        // In big recoveries, it is recommended to clear the caches.
        $this->entityManager->clear();

        // Entity are used, because it's not possible to get the value
        // "has_original" or "has_thumbnails" via api.
        $criteria = new \Doctrine\Common\Collections\Criteria();
        $expr = $criteria->expr();

        $isOriginalMain = count($types) === 1
            && reset($types) === 'original';
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
                ? $this->listFilesInFolder($path, false, $this->extensions)
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
                            $hash = $media->getSha256();
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
                                    $result = copy($src,$dest);
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

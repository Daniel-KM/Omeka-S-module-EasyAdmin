<?php
namespace BulkCheck\Job;

class FileMissing extends AbstractCheckFile
{
    /**
     * @var string
     */
    protected $sourceDir;

    /**
     * @var array
     */
    protected $files;

    public function perform()
    {
        parent::perform();

        $process = $this->getArg('process');

        $fix = $process === 'files_missing_fix';
        if ($fix) {
            $dir = rtrim($this->getArg('source_dir'), '/');
            if (!$dir || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
                $this->logger->err(
                    'Source directory "{path}" is not set or not readable.', // @translate
                    ['path' => $dir]
                );
                return;
            }

            if (realpath($dir) !== $dir || strlen($dir) <= 1) {
                $this->logger->err(
                    'Source directory "{path}" should be a real path.', // @translate
                    ['path' => $dir]
                );
                return;
            }

            $this->files = $this->listFilesInFolder($dir);
            if (!count($this->files)) {
                $this->logger->err(
                    'Source directory "{path}" is empty.', // @translate
                    ['path' => $dir]
                );
                return;
            }

            $this->sourceDir = $dir;

            $total = count($this->files);

            // Prepare a list of hash of all files one time.
            $this->logger->info(
                'Preparing hashes of {total} iles (this may take a long time).', // @translate
                ['total' => $total]
            );

            $count = 0;
            foreach ($this->files as $key => $file) {
                $filepath = $dir . '/' . $file;
                if (is_readable($filepath)) {
                    $this->files[hash_file('sha256', $filepath)] = $file;
                } else {
                    $this->logger->err(
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
        }

        $this->checkMissingFiles($fix);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        if ($fix) {
            $this->logger->warn(
                'The derivative files are not rebuilt automatically. Check them and recreate them via the other processes.' // @translate
            );
        }
    }

    protected function checkMissingFiles($fix = false)
    {
        $result = $this->checkMissingFilesForTypes(['original'], $fix);
        if (!$result) {
            return false;
        }
        $result = $this->checkMissingFilesForTypes(array_keys($this->config['thumbnails']['types']));
        return $result;
    }

    protected function checkMissingFilesForTypes(array $types, $fix = false)
    {
        $criteria = [];
        $isOriginal = in_array('original', $types);
        if ($isOriginal) {
            $criteria['hasOriginal'] = 1;
            $sql = 'SELECT COUNT(id) FROM media WHERE has_original = 1';
            $totalToProcess = $this->connection->query($sql)->fetchColumn();
            $this->logger->notice(
                'Checking {total} media with original files.', // @translate
                ['total' => $totalToProcess]
            );
        } else {
            $criteria['hasThumbnails'] = 1;
            $sql = 'SELECT COUNT(id) FROM media WHERE has_thumbnails = 1';
            $totalToProcess = $this->connection->query($sql)->fetchColumn();
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

        // First, list files.
        $types = array_flip($types);
        foreach (array_keys($types) as $type) {
            $path = $this->basePath . '/' . $type;
            $types[$type] = $this->listFilesInFolder($path);
        }

        // Second, loop all media data.
        $offset = 0;
        $key = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        while (true) {
            // Entity are used, because it's not possible to get the value
            // "has_original" or "has_thumbnails" via api.
            /** @var \Omeka\Entity\Media[] $medias */
            $medias = $this->mediaRepository->findBy($criteria, ['id' => 'ASC'], self::SQL_LIMIT, $offset);
            if (!count($medias)) {
                break;
            }

            if ($offset) {
                $this->logger->info(
                    '{processed}/{total} media processed.', // @translate
                    ['processed' => $offset, 'total' => $totalToProcess]
                );

                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job was stopped.' // @translate
                    );
                    return false;
                }
            }

            foreach ($medias as $key => $media) {
                foreach ($types as $type => $files) {
                    $filename = $isOriginal ? $media->getFilename() : ($media->getStorageId() . '.jpg');
                    if (in_array($filename, $files)) {
                        ++$totalSucceed;
                    } elseif ($fix) {
                        if ($type !== 'original') {
                            break;
                        }
                        $hash = $media->getSha256();
                        if (isset($this->files[$hash])) {
                            $result = copy(
                                $this->sourceDir . '/' . $this->files[$hash],
                                $this->basePath . '/original/' . $filename
                            );
                            if ($result) {
                                ++$totalSucceed;
                                $this->logger->info(
                                    'Media #{media_id} ({processed}/{total}): original file copied from source "{filepath}".', // @translate
                                    [
                                        'media_id' => $media->getId(),
                                        'processed' => $offset + $key + 1,
                                        'total' => $totalToProcess,
                                        'filepath' => $this->files[$hash],
                                    ]
                                );
                            } else {
                                ++$totalFailed;
                                $this->logger->warn(
                                    'Media #{media_id} ({processed}/{total}): unable to copy original file "{filepath}".', // @translate
                                    [
                                        'media_id' => $media->getId(),
                                        'processed' => $offset + $key + 1,
                                        'total' => $totalToProcess,
                                        'filepath' => $this->files[$hash],
                                    ]
                                );
                            }
                        } else {
                            ++$totalFailed;
                            $this->logger->warn(
                                'Media #{media_id} ({processed}/{total}): file "{filename}" does not does not have a source to copy.', // @translate
                                [
                                    'media_id' => $media->getId(),
                                    'processed' => $offset + $key + 1,
                                    'total' => $totalToProcess,
                                    'filename' => $filename,
                                ]
                            );
                        }
                    } else {
                        ++$totalFailed;
                        $this->logger->warn(
                            'Media #{media_id} ({processed}/{total}): file "{filename}" does not exist for type "{type}".', // @translate
                            [
                                'media_id' => $media->getId(),
                                'processed' => $offset + $key + 1,
                                'total' => $totalToProcess,
                                'filename' => $filename,
                                'type' => $type,
                            ]
                        );
                    }
                }

                ++$totalProcessed;

                // Avoid memory issue.
                unset($media);
            }

            // Avoid memory issue.
            unset($medias);
            $this->mediaRepository->clear();

            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed ({mode}).', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
                'total_succeed' => $totalSucceed,
                'total_failed' => $totalFailed,
                'mode' => $isOriginal ? 'original' : sprintf('%d thumbnails', count($types)),
            ]
        );

        return true;
    }
}

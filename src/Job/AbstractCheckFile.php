<?php declare(strict_types=1);

namespace EasyAdmin\Job;

abstract class AbstractCheckFile extends AbstractCheck
{
    /**
     * Check the size or the hash of the files.
     */
    protected function checkFileData(string $column, bool $fix = false): bool
    {
        if (!in_array($column, ['size', 'sha256', 'media_type', 'storage_id'])) {
            $this->logger->err(
                'Column {type} does not exist or cannot be checked.', // @translate
                ['type' => $column]
            );
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return false;
        }

        // This total should be 0.
        $sql = "SELECT COUNT(id) FROM media WHERE has_original != 1 AND $column IS NOT NULL";
        $totalNoOriginalSize = $this->connection->executeQuery($sql)->fetchOne();
        $sql = 'SELECT COUNT(id) FROM media WHERE has_original != 1';
        $totalNoOriginal = $this->connection->executeQuery($sql)->fetchOne();
        if ($totalNoOriginalSize) {
            if ($fix) {
                $sql = "UPDATE media SET $column = NULL WHERE has_original != 1 AND $column IS NOT NULL";
                $this->connection->executeStatement($sql);
                $this->logger->notice(
                    '{total_size}/{total_no} media have no original file, but a {type}, and were fixed.', // @translate
                    ['total_size' => $totalNoOriginalSize, 'total_no' => $totalNoOriginal, 'type' => $column]
                );
            } else {
                $this->logger->warn(
                    '{total_size}/{total_no} media have no original file, but a {type}.', // @translate
                    ['total_size' => $totalNoOriginalSize, 'total_no' => $totalNoOriginal, 'type' => $column]
                );
            }
        } else {
            $this->logger->notice(
                '{total_no} media have no original file, so no {type}.', // @translate
                ['total_no' => $totalNoOriginal, 'type' => $column]
            );
        }

        $criteria = [];
        $criteria['hasOriginal'] = 1;
        $sql = 'SELECT COUNT(id) FROM media WHERE has_original = 1';
        $totalToProcess = $this->connection->executeQuery($sql)->fetchOne();
        $this->logger->notice(
            'Checking {total} media with original files.', // @translate
            ['total' => $totalToProcess]
        );

        if (empty($totalToProcess)) {
            $this->logger->notice(
                'No media to process.' // @translate
            );
            return true;
        }

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate
        $empty = $translator->translate('[empty]'); // @translate

        $specifyMediaType = $this->getServiceLocator()->get('ControllerPluginManager')->get('specifyMediaType');

        // Loop all media with original files.
        $originalPath = $this->basePath . '/original';
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
                $filename = $media->getFilename();
                $filepath = $originalPath . '/' . $filename;

                $row = [
                    'item' => $media->getItem()->getId(),
                    'media' => $media->getId(),
                    'filename' => $filename,
                    'extension' => pathinfo($filename, PATHINFO_EXTENSION),
                    'exists' => '',
                    $column => '',
                    "real_$column" => '',
                    'fixed' => '',
                ];

                $isFixable = false;
                if (file_exists($filepath)) {
                    $row['exists'] = $yes;
                    switch ($column) {
                        case 'size':
                            $dbValue = $media->getSize();
                            $realValue = filesize($filepath);
                            $isFixable = true;
                            break;
                        case 'sha256':
                            $dbValue = $media->getSha256();
                            $realValue = hash_file('sha256', $filepath);
                            $isFixable = true;
                            break;
                        case 'media_type':
                            $dbValue = $media->getMediaType();
                            $realValue = $specifyMediaType($filepath);
                            $isFixable = true;
                            break;
                        case 'storage_id':
                            /** @see \Omeka\File\TempFile::getStorageId() */
                            $dbValue = $media->getStorageId();
                            $realValue = bin2hex(\Laminas\Math\Rand::getBytes(20));
                            // No check for existing file, such like TempFile:
                            // this is a 20 hexa random name.
                            $isFixable = $dbValue && is_writeable($filepath);
                            if (!$dbValue) {
                                $this->logger->warn(
                                    'Media #{media_id} ({processed}/{total}): original file "{filename}" has no value and cannot be renamed.', // @translate
                                    [
                                        'media_id' => $media->getId(),
                                        'processed' => $offset + $key + 1,
                                        'total' => $totalToProcess,
                                        'filename' => $filename,
                                    ]
                                );
                            } elseif (!is_writeable($filepath)) {
                                $this->logger->warn(
                                    'Media #{media_id} ({processed}/{total}): original file "{filename}" is write protected and cannot be renamed.', // @translate
                                    [
                                        'media_id' => $media->getId(),
                                        'processed' => $offset + $key + 1,
                                        'total' => $totalToProcess,
                                        'filename' => $filename,
                                    ]
                                );
                            }
                            break;
                    }

                    $isDifferent = $dbValue != $realValue;

                    $row[$column] = $dbValue;
                    $row["real_$column"] = $realValue;

                    if ($fix) {
                        if ($isDifferent) {
                            $isFixed = false;
                            switch ($column) {
                                case 'size':
                                    $media->setSize($realValue);
                                    $isFixed = true;
                                    break;
                                case 'sha256':
                                    $media->setSha256($realValue);
                                    $isFixed = true;
                                    break;
                                case 'media_type':
                                    $media->setMediaType($realValue);
                                    $isFixed = true;
                                    break;
                                case 'storage_id':
                                    if ($isFixable) {
                                        $extension = $media->getExtension();
                                        $newFilepath = dirname($filepath) . '/' . $realValue . ($extension !== null && $extension !== '' ? '.' . $extension : '');
                                        // TODO Ideally, the rename should occur at the same time than flush.
                                        // For now, add a real time log to avoid this rare issue.
                                        $isFixed = rename($filepath, (string) $newFilepath);
                                        if ($isFixed) {
                                            $media->setStorageId($realValue);
                                            $this->logger->notice(
                                                'Media #{media_id} ({processed}/{total}): original file "{filename}" was renamed "{filename_2}".', // @translate
                                                [
                                                    'media_id' => $media->getId(),
                                                    'processed' => $offset + $key + 1,
                                                    'total' => $totalToProcess,
                                                    'filename' => $filename,
                                                    'filename_2' => basename($newFilepath),
                                                ]
                                            );
                                        } else {
                                            $this->logger->warn(
                                                'Media #{media_id} ({processed}/{total}): original file "{filename}" cannot be renamed "{filename_2}".', // @translate
                                                [
                                                    'media_id' => $media->getId(),
                                                    'processed' => $offset + $key + 1,
                                                    'total' => $totalToProcess,
                                                    'filename' => $filename,
                                                    'filename_2' => basename($newFilepath),
                                                ]
                                            );
                                        }
                                    }
                                    break;
                            }
                            $this->entityManager->persist($media);
                            // Don't repeat log for storage and it may be issue.
                            if ($column !== 'storage_id') {
                                $this->logger->notice(
                                    'Media #{media_id} ({processed}/{total}): original file "{filename}" updated with {type} = {real_value} (was {old_value}).', // @translate
                                    [
                                        'media_id' => $media->getId(),
                                        'processed' => $offset + $key + 1,
                                        'total' => $totalToProcess,
                                        'filename' => $filename,
                                        'type' => $column,
                                        'real_value' => $realValue,
                                        'old_value' => $dbValue ?: $empty,
                                    ]
                                );
                            }
                        } else {
                            $isFixed = true;
                        }
                        ++$totalSucceed;
                        $row['fixed'] = $isFixed ? $yes : $no;
                    } else {
                        if ($column === 'storage_id') {
                            // Messages are set above and the real value is random.
                            $isFixable
                                ? ++$totalSucceed
                                : ++$totalFailed;
                        } elseif ($dbValue === null) {
                            ++$totalFailed;
                            $this->logger->warn(
                                'Media #{media_id} ({processed}/{total}): original file "{filename}" has no {type}, but should be {real_value}.', // @translate
                                [
                                    'media_id' => $media->getId(),
                                    'processed' => $offset + $key + 1,
                                    'total' => $totalToProcess,
                                    'filename' => $filename,
                                    'type' => $column,
                                    'real_value' => $realValue,
                                ]
                            );
                        } elseif ($isDifferent) {
                            ++$totalFailed;
                            $this->logger->warn(
                                'Media #{media_id} ({processed}/{total}): original file "{filename}" has a different {type}: {db_value} ≠ {real_value}.', // @translate
                                [
                                    'media_id' => $media->getId(),
                                    'processed' => $offset + $key + 1,
                                    'total' => $totalToProcess,
                                    'filename' => $filename,
                                    'type' => $column,
                                    'db_value' => $dbValue,
                                    'real_value' => $realValue,
                                ]
                            );
                        } else {
                            ++$totalSucceed;
                        }
                    }
                } else {
                    ++$totalFailed;
                    $this->logger->warn(
                        'Media #{media_id} ({processed}/{total}): original file "{filename}" does not exist.', // @translate
                        [
                            'media_id' => $media->getId(),
                            'processed' => $offset + $key + 1,
                            'total' => $totalToProcess,
                            'filename' => $filename,
                        ]
                    );

                    $row['exists'] = $no;
                }

                $this->writeRow($row);

                ++$totalProcessed;
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            unset($medias);

            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed.', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
                'total_succeed' => $totalSucceed,
                'total_failed' => $totalFailed,
            ]
        );

        return true;
    }

    protected function createDir($path): bool
    {
        return file_exists($path)
            ? is_dir($path) && is_writeable($path)
            : @mkdir($path, 0775, true);
    }

    /**
     * List files inside a directory filtered by extensions recursively.
     */
    protected function listFilesInFolder(
        string $dir,
        bool $absolute = false,
        array $extensions = [],
        array $extensionsExclude = [],
        array $filenamesEndExclude = []
    ): array {
        if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }

        $regex = empty($extensions)
            ? '/^.+$/i'
            : '/^.+\.(' . implode('|', $extensions) . ')$/i';

        $excludedRegex = empty($extensionsExclude)
            ? null
            : '/^.+\.(' . implode('|', $extensionsExclude) . ')$/i';

        $directory = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, $regex, \RecursiveRegexIterator::GET_MATCH);

        $dirLength = mb_strlen($dir) + 1;

        $excludedFilenamesEnd = [];
        foreach ($filenamesEndExclude ?? [] as $excludeString) {
            $excludedFilenamesEnd[$excludeString] = mb_strlen($excludeString);
        }

        $files = [];

        try {
            foreach ($regex as $file) {
                $filePath = reset($file);
                if ($excludedRegex && preg_match($excludedRegex, $filePath)) {
                    continue;
                }

                // Skip files ending with excluded strings
                if ($excludedFilenamesEnd) {
                    $filename = pathinfo($filePath, PATHINFO_FILENAME);
                    $shouldSkip = false;
                    foreach ($excludedFilenamesEnd as $excludeString => $length) {
                        if (mb_substr($filename, -$length) === $excludeString) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    if ($shouldSkip) {
                        continue;
                    }
                }

                $files[] = $absolute ? $filePath : mb_substr($filePath, $dirLength);
            }
        } catch (\Exception $e) {
            $this->logger->err(
                'Directory or file not readable: {error}', // @translate
                ['exception' => $e->getMessage()]
            );
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
        }

        sort($files);
        return $files;
    }
}

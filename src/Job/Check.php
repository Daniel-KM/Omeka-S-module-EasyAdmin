<?php
namespace BulkCheck\Job;

use Omeka\Job\AbstractJob;

class Check extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 100;

    /**
     * @var int
     */
    const SESSION_OLD_DAYS = 100;

    /**
     * @var \Zend\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $mediaRepository;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $basePath;

    public function perform()
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Zend\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/check/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);
        $this->api = $services->get('ControllerPluginManager')->get('api');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->connection = $services->get('Omeka\Connection');
        $this->connection = $this->entityManager->getConnection();
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);
        $this->config = $services->get('Config');
        $this->basePath = $this->config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // TODO Add a tsv output in /check/process_date_time.tsv.

        $process = $this->getArg('process');
        $processs = [
            'files_excess',
            'files_excess_move',
            // TODO Check files with the wrong extension.
            'files_missing',
            'dirs_excess',
            'filesize_check',
            'filesize_fix',
            'filehash_check',
            'filehash_fix',
            'db_job_check',
            'db_job_clean',
            'db_job_clean_all',
            'db_session_check',
            'db_session_clean',
        ];
        if (!in_array($process, $processs)) {
            $this->logger->notice(
                'Process "{process}" is unknown.', // @translate
                ['process' => $process]
            );
            return;
        }

        $this->logger->notice(
            'Starting "{process}".', // @translate
            ['process' => $process]
        );
        switch ($process) {
            case 'files_excess':
                $this->checkExcessFiles();
                break;
            case 'files_excess_move':
                if (!$this->createDir($this->basePath . '/check')) {
                    $this->logger->err(
                        'Unable to prepare directory "{path}". Check rights.', // @translate
                        ['path' => '/files/check']
                    );
                    return;
                }
                $this->checkExcessFiles(true);
                break;
            case 'files_missing':
                $this->checkMissingFiles();
                break;
            case 'dirs_excess':
                $this->removeEmptyDirs();
                break;
            case 'filesize_check':
            case 'filesize_fix':
                $this->checkFilesize($process === 'filesize_fix');
                break;
            case 'filehash_check':
            case 'filehash_fix':
                $this->checkFilehash($process === 'filehash_fix');
                break;
            case 'db_job_check':
            case 'db_job_clean':
                $this->checkDbJob($process === 'db_job_clean');
                break;
            case 'db_job_clean_all':
                $this->checkDbJob(true, true);
                break;
            case 'db_session_check':
            case 'db_session_clean':
                $this->checkDbSession($process === 'db_session_clean');
                break;
        }

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function checkExcessFiles($move = false)
    {
        if ($move) {
            $path = $this->basePath . '/check/original';
            if (!$this->createDir($path)) {
                $this->logger->err(
                    'Unable to prepare directory "{path}". Check rights.', // @translate
                    ['path' => '/files/check/original']
                );
                return false;
            }
        }

        $result = $this->checkExcessFilesForType('original', $move);
        if (!$result) {
            return false;
        }

        foreach (array_keys($this->config['thumbnails']['types']) as $type) {
            if ($move) {
                $path = $this->basePath . '/check/' . $type;
                if (!$this->createDir($path)) {
                    $this->logger->err(
                        'Unable to prepare directory "{path}". Check rights.', // @translate
                        ['path' => '/files/check/' . $type]
                    );
                    return false;
                }
            }

            $result = $this->checkExcessFilesForType($type, $move);
            if (!$result) {
                return false;
            }
        }

        return true;
    }

    protected function checkExcessFilesForType($type, $move)
    {
        $path = $this->basePath . '/' . $type;
        $isOriginal = $type === 'original';

        // Creation of a folder is required for module ArchiveRepertory
        // or some other ones. Nevertheless, the check is not done for
        // performance reasons (and generally useless).
        if ($move) {
            $movePath = $this->basePath . '/check/' . $type;
            $this->createDir(dirname($movePath));
        }

        $files = $this->listFilesInFolder($path);

        $total = count($files);
        $totalSuccess = 0;
        $totalExcess = 0;

        $this->logger->notice(
            'Starting check of {total} files for type {type}.', // @translate
            ['total' => $total]
        );

        $i = 0;
        foreach ($files as $filename) {
            if (($i % 100 === 0) && $i) {
                $this->logger->notice(
                    '{processed}/{total} files processed.', // @translate
                    ['processed' => $i, 'total' => $total]
                );
                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job was stopped.' // @translate
                    );
                    return false;
                }
            }
            ++$i;

            if ($isOriginal) {
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $storageId = strlen($extension)
                    ? substr($filename, 0, strrpos($filename, '.'))
                    : $filename;
                $column = 'hasOriginal';
            } else {
                $extension = 'jpg';
                $storageId = substr($filename, 0, strrpos($filename, '.'));
                $column = 'hasThumbnails';
            }

            // TODO Find original file with a different extension.
            $media = $this->mediaRepository->findOneBy([
                'storageId' => $storageId,
                'extension' => $extension,
                $column => 1,
            ]);
            if ($media) {
                ++$totalSuccess;
                $this->mediaRepository->clear();
                continue;
            }

            if ($move) {
                // Creation of a folder is required for module ArchiveRepertory
                // or some other ones.
                $dirname = dirname($movePath . '/' . $filename);
                if ($dirname !== $movePath) {
                    if (!$this->createDir($dirname)) {
                        $this->logger->err(
                            'Unable to prepare directory "{path}". Check rights.', // @translate
                            ['path' => '/files/check/' . $type . '/' . dirname($filename)]
                        );
                        return false;
                    }
                }
                $result = @rename($path . '/' . $filename, $movePath . '/' . $filename);
                if ($result) {
                    $this->logger->warn(
                        'File "{filename}" ("{type}", {processed}/{total}) doesn’t exist in database and was moved.', // @translate
                        ['filename' => $filename, 'type' => $type, 'processed' => $i, 'total' => $total]
                    );
                } else {
                    $this->logger->err(
                        'File "{filename}" (type "{type}") doesn’t exist in database, and cannot be moved.', // @translate
                        ['filename' => $filename, 'type' => $type]
                    );
                    return false;
                }
            } else {
                $this->logger->warn(
                    'File "{filename}" ("{type}", {processed}/{total}) doesn’t exist in database.', // @translate
                    ['filename' => $filename, 'type' => $type, 'processed' => $i, 'total' => $total]
                );
            }

            ++$totalExcess;
            $this->mediaRepository->clear();
        }

        if ($move) {
            $this->logger->notice(
                'End check of {total} files for type {type}: {total_excess} files in excess.', // @translate
                ['total' => count($files), 'type' => $type, 'total_excess' => $totalExcess]
            );
        } else {
            $this->logger->notice(
                'End check of {total} files for type {type}: {total_excess} files in excess moved.', // @translate
                ['total' => count($files), 'type' => $type, 'total_excess' => $totalExcess]
            );
        }

        return true;
    }

    protected function checkMissingFiles()
    {
        $result = $this->checkMissingFilesForTypes(['original']);
        if (!$result) {
            return false;
        }
        $result = $this->checkMissingFilesForTypes(array_keys($this->config['thumbnails']['types']));
        return $result;
    }

    protected function checkMissingFilesForTypes(array $types)
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
                $this->logger->notice(
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

    protected function removeEmptyDirs()
    {
        $result = $this->removeEmptyDirsForType('original');
        if (!$result) {
            return false;
        }

        foreach (array_keys($this->config['thumbnails']['types']) as $type) {
            $result = $this->removeEmptyDirsForType($type);
            if (!$result) {
                return false;
            }
        }

        return true;
    }

    protected function removeEmptyDirsForType($type)
    {
        $path = $this->basePath . '/' . $type;
        $this->logger->notice(
            'Processing type "{type}".', // @translate
            ['type' => $type]
        );
        $this->removeEmptySubFolders($path, true);
        return true;
    }

    /**
     * Check the size of the files.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkFilesize($fix = false)
    {
        return $this->checkFileData('size', $fix);
    }

    /**
     * Check the hash of the files.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkFilehash($fix = false)
    {
        return $this->checkFileData('sha256', $fix);
    }

    /**
     * Check the size or the hash of the files.
     *
     * @param string $column
     * @param bool $fix
     * @return bool
     */
    protected function checkFileData($column, $fix = false)
    {
        if (!in_array($column, ['size', 'sha256'])) {
            $this->logger->error(
                'Column {type} does not exist or cannot be checked.', // @translate
                ['type' => $column]
            );
            return false;
        }

        // This total should be 0.
        $sql = "SELECT COUNT(id) FROM media WHERE has_original != 1 AND $column IS NOT NULL";
        $totalNoOriginalSize = $this->connection->query($sql)->fetchColumn();
        $sql = 'SELECT COUNT(id) FROM media WHERE has_original != 1';
        $totalNoOriginal = $this->connection->query($sql)->fetchColumn();
        if ($totalNoOriginalSize) {
            if ($fix) {
                $sql = "UPDATE media SET $column = NULL WHERE has_original != 1 AND $column IS NOT NULL";
                $this->connection->exec($sql);
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
        $totalToProcess = $this->connection->query($sql)->fetchColumn();
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
                $this->logger->notice(
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
                if (file_exists($filepath)) {
                    switch ($column) {
                        case 'size':
                            $dbValue = $media->getSize();
                            $realValue = filesize($filepath);
                            break;
                        case 'sha256':
                            $dbValue = $media->getSha256();
                            $realValue = hash_file('sha256', $filepath);
                            break;
                    }

                    $isDifferent = $dbValue != $realValue;
                    if ($fix) {
                        if ($isDifferent) {
                            switch ($column) {
                                case 'size':
                                    $media->setSize($realValue);
                                    break;
                                case 'sha256':
                                    $media->setSha256($realValue);
                                    break;
                            }
                            $this->entityManager->persist($media);
                        }
                        $this->logger->notice(
                            'Media #{media_id} ({processed}/{total}): original file "{filename}" updated with {type} = {real_value}.', // @translate
                            [
                                'media_id' => $media->getId(),
                                'processed' => $offset + $key + 1,
                                'total' => $totalToProcess,
                                'filename' => $filename,
                                'type' => $column,
                                'real_value' => $realValue,
                            ]
                        );
                        ++$totalSucceed;
                    } else {
                        if (is_null($dbValue)) {
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
                        'Media #{media_id} ({processed}/{total}): original file "{filename}" does not exist".', // @translate
                        [
                            'media_id' => $media->getId(),
                            'processed' => $offset + $key + 1,
                            'total' => $totalToProcess,
                            'filename' => $filename,
                        ]
                    );
                }

                ++$totalProcessed;
            }

            $this->entityManager->flush();
            $this->mediaRepository->clear();
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

    /**
     * Check the never ending jobs.
     *
     * @param bool $fix
     * @param bool $fixAll
     * @return bool
     */
    protected function checkDbJob($fix = false, $fixAll = false)
    {
        $sql = <<<SQL
SELECT id, pid, status
FROM job
WHERE id != :jobid
    AND status IN ("starting", "stopping", "in_progress")
ORDER BY id ASC;
SQL;

        // Fetch all: jobs are few, except if admin never checks result of jobs.
        $result = $this->connection->executeQuery($sql, ['jobid' => $this->job->getId()])->fetchAll(\PDO::FETCH_ASSOC);

        // Unselect processes with an existing pid.
        foreach ($result as $id => $row) {
            // TODO The check of the pid works only with Linux.
            if ($row['pid'] && file_exists('/proc/' . $row['pid'])) {
                unset($result[$id]);
            }
        }

        if ($fixAll) {
            $sql = 'SELECT COUNT(id) FROM job';
            $countJobs = $this->connection->query($sql)->fetchColumn();

            $sql = <<<SQL
UPDATE job
SET status = "stopped"
WHERE id != :jobid
    AND status IN ("starting", "stopping");
SQL;
            $stopped = $this->connection->executeQuery($sql, ['jobid' => $this->job->getId()])->rowCount();

            $sql = <<<SQL
UPDATE job
SET status = "error"
WHERE id != :jobid
    AND status IN ("in_progress");
SQL;
            $error = $this->connection->executeQuery($sql, ['jobid' => $this->job->getId()])->rowCount();

            $this->logger->notice(
                'Dead jobs were cleaned: {count_stopped} marked "stopped" and {count_error} marked "error" on a total of {count_jobs}.', // @translate
                [
                    'count_stopped' => $stopped,
                    'count_error' => $error,
                    'count_jobs' => $countJobs,
                ]
            );
            return;
        }

        if (empty($result)) {
            $this->logger->notice(
                'There is no dead job.' // @translate
            );
            return;
        }

        $this->logger->notice(
            'The following {count} jobs are dead: {jobs}.', // @translate
            [
                'count' => count($result),
                'jobs' => implode(', ', array_map(function ($v) {
                    return '#' . $v['id'];
                }, $result)),
            ]
        );

        if ($fix) {
            $stopped = [];
            $errored = [];
            foreach ($result as $value) {
                if ($value['status'] === 'in_progress') {
                    $errored[] = (int) $value['id'];
                } else {
                    $stopped[] = (int) $value['id'];
                }
            }

            if ($stopped) {
                $sql = 'UPDATE job SET status = "stopped" WHERE id IN (' . implode(',', $stopped) . ')';
                $this->connection->exec($sql);
            }

            if ($errored) {
                $sql = 'UPDATE job SET status = "error" WHERE id IN (' . implode(',', $errored) . ')';
                $this->connection->exec($sql);
            }

            $this->logger->notice(
                'A total of {count} dead jobs have been cleaned.', // @translate
                ['count' => count($result)]
            );
        }
    }

    /**
     * Check the size of the db table session.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkDbSession($fix = false)
    {
        $timestamp = time() - 86400 * self::SESSION_OLD_DAYS;

        $dbname = $this->connection->getDatabase();
        $sqlSize = <<<SQL
SELECT ROUND((data_length + index_length) / 1024 / 1024, 2)
FROM information_schema.TABLES
WHERE table_schema = "$dbname"
    AND table_name = "session";
SQL;
        $size = $this->connection->query($sqlSize)->fetchColumn();
        $sql = 'SELECT COUNT(id) FROM session WHERE modified < :timestamp;';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':timestamp', $timestamp);
        $stmt->execute();
        $old = $stmt->fetchColumn();
        $sql = 'SELECT COUNT(id) FROM session;';
        $all = $this->connection->query($sql)->fetchColumn();
        $this->logger->notice(
            'The table "session" has a size of {size} MB. {old}/{all} records are older than 100 days.', // @translate
            ['size' => $size, 'old' => $old, 'all' => $all]
        );

        if ($fix) {
            $sql = 'DELETE FROM `session` WHERE modified < :timestamp;';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue(':timestamp', $timestamp);
            $stmt->execute();
            $count = $stmt->rowCount();
            $size = $this->connection->query($sqlSize)->fetchColumn();
            $this->logger->notice(
                '{count} records older than {days} days were removed. The table "session" has a size of {size} MB.', // @translate
                ['count' => $count, 'days' => self::SESSION_OLD_DAYS, 'size' => $size]
            );
        }
    }

    /**
     * Get a relative or full path of files filtered by extensions recursively
     * in a directory.
     *
     * @param string $dir
     * @param bool $absolute
     * @param string $extensions
     * @return array
     */
    protected function listFilesInFolder($dir, $absolute = false, array $extensions = [])
    {
        if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }
        $regex = empty($extensions)
            ? '/^.+$/i'
            : '/^.+\.(' . implode('|', $extensions) . ')$/i';
        $directory = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, $regex, \RecursiveRegexIterator::GET_MATCH);
        $files = [];
        if ($absolute) {
            foreach ($regex as $file) {
                $files[] = reset($file);
            }
        } else {
            $dirLength = strlen($dir) + 1;
            foreach ($regex as $file) {
                $files[] = substr(reset($file), $dirLength);
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Remove empty sub-folders recursively.
     *
     * @see https://stackoverflow.com/questions/1833518/remove-empty-subfolders-with-php
     *
     * @param string $path
     * @param bool $root
     * @return bool
     */
    protected function removeEmptySubFolders($path, $root = false)
    {
        $empty = true;
        foreach (glob($path . DIRECTORY_SEPARATOR . '{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) as $file) {
            $empty &= is_dir($file) && $this->removeEmptySubFolders($file);
        }
        return $empty && !$root && rmdir($path);
    }

    protected function createDir($path)
    {
        return file_exists($path)
            ? (is_dir($path) ? is_writeable($path) : false)
            : @mkdir($path, 0775, true);
    }
}

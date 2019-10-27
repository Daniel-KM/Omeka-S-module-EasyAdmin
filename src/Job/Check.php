<?php
namespace BulkCheck\Job;

use Omeka\Job\AbstractJob;

class Check extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var integer
     */
    const SQL_LIMIT = 100;

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
        $this->logger = $services->get('Omeka\Logger');
        $this->api = $services->get('ControllerPluginManager')->get('api');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->connection = $services->get('Omeka\Connection');
        $this->connection = $this->entityManager->getConnection();
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);
        $this->config = $services->get('Config');
        $this->basePath = $this->config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // TODO Add a tsv output in /check/process_date_time.tsv.

        $processMode = $this->getArg('process_mode');
        $processModes = [
            'files_excess',
            'files_excess_move',
            // TODO Check files with the wrong extension.
            'files_missing',
            'dirs_excess',
            'filesize_check',
            'filesize_fix',
            'filehash_check',
            'filehash_fix',
        ];
        if (!in_array($processMode, $processModes)) {
            $this->logger->info(
                'Process mode "{process_mode}" is unknown.', // @translate
                ['process_mode' => $processMode]
            );
            return;
        }

        $this->logger->notice(
            'Starting "{process_mode}".', // @translate
            ['process_mode' => $processMode]
        );
        switch ($processMode) {
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
                $this->checkFilesize($processMode === 'filesize_fix');
                break;
            case 'filehash_check':
            case 'filehash_fix':
                $this->checkFilehash($processMode === 'filehash_fix');
                break;
        }

        $this->logger->notice(
            'Process "{process_mode}" completed.', // @translate
            ['process_mode' => $processMode]
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

        $this->logger->info(
            'Starting check of {total} files for type {type}.', // @translate
            ['total' => $total]
        );

        $i = 0;
        foreach ($files as $filename) {
            if (($i % 100 === 0) && $i) {
                $this->logger->info(
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
            $this->logger->info(
                'End check of {total} files for type {type}: {total_excess} files in excess.', // @translate
                ['total' => count($files), 'type' => $type, 'total_excess' => $totalExcess]
            );
        } else {
            $this->logger->info(
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
            $this->logger->info(
                'Checking {total} media with original files.', // @translate
                ['total' => $totalToProcess]
            );
        } else {
            $criteria['hasThumbnails'] = 1;
            $sql = 'SELECT COUNT(id) FROM media WHERE has_thumbnails = 1';
            $totalToProcess = $this->connection->query($sql)->fetchColumn();
            $this->logger->info(
                'Checking {total} media with thumbnails.', // @translate
                ['total' => $totalToProcess]
            );
        }

        if (empty($totalToProcess)) {
            $this->logger->info(
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

        $this->logger->info(
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
        $this->logger->info(
            'Processing type "{type}".', // @translate
            ['type' => $type]
        );
        $this->removeEmptySubFolders($path, true);
        return true;
    }

    /**
     * Check the size of the files.
     *
     * @param boolean $fix
     * @return boolean
     */
    protected function checkFilesize($fix = false)
    {
        return $this->checkFileData('size', $fix);
    }

    /**
     * Check the hash of the files.
     *
     * @param boolean $fix
     * @return boolean
     */
    protected function checkFilehash($fix = false)
    {
        return $this->checkFileData('sha256', $fix);
    }

    /**
     * Check the size or the hash of the files.
     *
     * @param string $column
     * @param boolean $fix
     * @return boolean
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
                $this->logger->notice (
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
        $this->logger->info(
            'Checking {total} media with original files.', // @translate
            ['total' => $totalToProcess]
        );

        if (empty($totalToProcess)) {
            $this->logger->info(
                'No media to process.' // @translate
            );
            return true;
        }

        // First, prepare the list of files and file data to check.
        $path = $this->basePath . '/original';
        $filedata = $this->listFilesInFolder($path, true);
        $filedata = array_fill_keys($filedata, null);
        foreach ($filedata as $filepath => &$filecheck) {
            switch ($column) {
                case 'size':
                    $filecheck = filesize($filepath);
                    break;
                case 'sha256':
                    $filecheck = hash_file('sha256', $filepath);
                    break;
            }
        }
        unset($filecheck);

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
                $filename = $media->getFilename();
                $filepath = $path . '/' . $filename;
                if (array_key_exists($filepath, $filedata)) {
                    switch ($column) {
                        case 'size':
                            $check = $media->getSize();
                            break;
                        case 'sha256':
                            $check = $media->getSha256();
                            break;
                    }

                    if ($fix) {
                        if ($check != $filedata[$filepath]) {
                            switch ($column) {
                                case 'size':
                                    $media->setSize($filedata[$filepath]);
                                    break;
                                case 'sha256':
                                    $media->setSha256($filedata[$filepath]);
                                    break;
                            }
                            $this->entityManager->persist($media);
                        }
                        ++$totalSucceed;
                    } else {
                        if (is_null($check)) {
                            ++$totalFailed;
                            $this->logger->warn(
                                'Media #{media_id} ({processed}/{total}): original file "{filename}" has no {type}.', // @translate
                                [
                                    'media_id' => $media->getId(),
                                    'processed' => $offset + $key + 1,
                                    'total' => $totalToProcess,
                                    'filename' => $filename,
                                    'type' => $column,
                                ]
                            );
                        } elseif ($check != $filedata[$filepath]) {
                            ++$totalFailed;
                            $this->logger->warn(
                                'Media #{media_id} ({processed}/{total}): original file "{filename}" has a different {type}.', // @translate
                                [
                                    'media_id' => $media->getId(),
                                    'processed' => $offset + $key + 1,
                                    'total' => $totalToProcess,
                                    'filename' => $filename,
                                    'type' => $column,
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

        $this->logger->info(
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

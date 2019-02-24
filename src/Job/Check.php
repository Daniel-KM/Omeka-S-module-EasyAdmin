<?php
namespace BulkCheck\Job;

use Omeka\Job\AbstractJob;
use Zend\Log\Logger;

class Check extends AbstractJob
{
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

        $processMode = $this->getArg('process_mode');
        $processModes = [
            'files_excess',
            'files_excess_move',
        ];
        if (!in_array($processMode, $processModes)) {
            $this->logger->info(
                'Process mode "process_mode}" is unknown.', // @translate
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
                    $this->logger->error(
                        'Unable to prepare directory "{path}". Check rights.', // @translate
                        ['path' => '/files/check']
                    );
                    return;
                }
                $this->checkExcessFiles(true);
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
                $this->logger->error(
                    'Unable to prepare directory "{path}". Check rights.', // @translate
                    ['path' => '/files/check/original']
                );
                return;
            }
        }
        $this->checkExcessFilesForType('original', $move);
        foreach (array_keys($this->config['thumbnails']['types']) as $type) {
            if ($move) {
                $path = $this->basePath . '/check/' . $type;
                if (!$this->createDir($path)) {
                    $this->logger->error(
                        'Unable to prepare directory "{path}". Check rights.', // @translate
                        ['path' => '/files/check/' . $type]
                    );
                    return;
                }
            }
            $this->checkExcessFilesForType($type, $move);
        }
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

        $pathLength = strlen($path) + 1;

        $files = $this->listFilesInFolder($path);

        $total = count($files);
        $totalSuccess = 0;
        $totalExcess = 0;

        $this->logger->info(
            'Starting check of {total} files for type {type}.', // @translate
            ['total' => $total]
        );

        $i = 0;
        foreach ($files as $filepath) {
            ++$i;
            if ($i % 100 === 0) {
                $this->logger->info(
                    '{processed}/{total} files processed.', // @translate
                    ['processed' => $i, 'total' => $total]
                );
            }
            $filename = substr($filepath, $pathLength);
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
                        $this->logger->error(
                            'Unable to prepare directory "{path}". Check rights.', // @translate
                            ['path' => '/files/check/' . $type . '/' . dirname($filename)]
                        );
                        return;
                    }
                }
                $result = @rename($path . '/' . $filename, $movePath . '/' . $filename);
                if ($result) {
                    $this->logger->warn(
                        'File "{filename}" ("{type}", {processed}/{total}) doesn’t exist in database and was moved.', // @translate
                        ['filename' => $filename, 'type' => $type, 'processed' => $i, 'total' => $total]
                    );
                } else {
                    $this->logger->error(
                        'File "{filename}" (type "{type}") doesn’t exist in database, and cannot be moved.', // @translate
                        ['filename' => $filename, 'type' => $type]
                    );
                    return;
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
    }

    /**
     * Get full path of files filtered by extensions recursively in a directory.
     *
     * @param string $dir
     * @param string $extensions
     * @return array
     */
    protected  static function listFilesInFolder($dir, array $extensions = [])
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
        foreach ($regex as $file) {
            $files[] = reset($file);
        }
        sort($files);
        return $files;
    }

    protected function createDir($path)
    {
        return file_exists($path)
            ? (is_dir($path) ? is_writeable($path) : false)
            : @mkdir($path, 0775, true);
    }
}

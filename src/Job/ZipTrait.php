<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Adapted from module HistoryLog.
 */
trait ZipTrait
{
    /**
     * @var \Omeka\Stdlib\Cli
     */
    protected $cli;

    protected $zipGenerator = '';

    /**
     * Check if the server support zip.
     */
    protected function prepareZipProcessor(): bool
    {
        if (class_exists('ZipArchive') && method_exists('ZipArchive', 'setCompressionName')) {
            $this->zipGenerator = 'ZipArchive';
            return true;
        }

        try {
            $commandPath = $this->cli->getCommandPath('zip');
            if (!$commandPath) {
                return false;
            }
            $this->zipGenerator = trim($commandPath);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Zip a directory to a destination.
     *
     * @param string $source Full source dir path.
     * @param string $destination Full destination file name.
     * @param array $exclude Relative paths of folders and files from source.
     * @param int $compression From 0 to 9; -1 means auto.
     * @return array The result contains:
     *   - error (bool): true if error
     *   - total (int): Number of compressed directories and files
     *   - total_dirs (int): Number of compressed directories
     *   - total_files (int): Number of compressed files
     *   - total_size (int): Total size of original files
     *   - size (int): size of the archive
     */
    protected function zip(
        string $source,
        string $destination,
        array $exclude = [],
        int $compression = -1
    ): array {
        $source = rtrim($source, '/\\');

        if ($compression > 9) {
            $compression = 9;
        } elseif ($compression < 0) {
            $compression = -1;
        }

        $result = [
            'error' => false,
            'total' => 0,
            'total_dirs' => 0,
            'total_files' => 0,
            'total_size' => 0,
            'size' => 0,
        ];

        if ($this->zipGenerator === 'ZipArchive') {
            // Create the zip.
            $zip = new ZipArchive();
            if ($zip->open($destination, ZipArchive::CREATE) !== true) {
                $this->logger->err(
                    'An error occurred during creation of the zip archive.' // @translate
                );
                $result['error'] = true;
                return $result;
            }

            $skipHidden = in_array('.*', $exclude);
            $skipZip = in_array('*.zip', $exclude);
            $sourceLength = mb_strlen($source);

            // TODO Find a better way to set the compression level for ZipArchive.
            // In fact, "default" is "deflate".
            /*
            $compressions = [
                -1 => ZipArchive::CM_DEFAULT,
                0 => ZipArchive::CM_STORE,
                1 => ZipArchive::CM_REDUCE_1,
                2 => ZipArchive::CM_REDUCE_2,
                3 => ZipArchive::CM_REDUCE_3,
                4 => ZipArchive::CM_REDUCE_4,
                5 => ZipArchive::CM_IMPLODE,
                6 => ZipArchive::CM_DEFLATE,
                7 => ZipArchive::CM_DEFLATE64,
                8 => ZipArchive::CM_BZIP2,
                9 => ZipArchive::CM_LZMA,
            ];
            $compressionName = $compressions[$compressioName] ?? ZipArchive::CM_DEFAULT;
             */
            $compressionName = ZipArchive::CM_DEFAULT;

            if (method_exists($zip, 'isCompressionMethodSupported')
                && !$zip->isCompressionMethodSupported($compressionName)
            ) {
                $this->logger->err(
                    'The compression level is not supported. Using default config.' // @translate
                );
                $compressionName = ZipArchive::CM_DEFAULT;
            }

            $excludedDirs = [];
            $excludedFiles = ['.', '..'];
            $excludedDirsRegex = [];
            foreach ($exclude as $excluded) {
                if (mb_substr($excluded, -1) === '/') {
                    $name = $source . '/' . rtrim($excluded, '/\\');
                    $excludedDirs[] = $name;
                    $excludedDirsRegex[] = preg_quote($name . '/', '/');
                } else {
                    $excludedFiles[] = $source . '/' . $excluded;
                }
            }
            $excludedDirsRegex = $excludedDirsRegex ? '/^(?:' . implode('|', $excludedDirsRegex) . ')/u' : '';

            $directoryIterator = new RecursiveDirectoryIterator(
                $source,
                FilesystemIterator::KEY_AS_PATHNAME
                | FilesystemIterator::CURRENT_AS_FILEINFO
                | FilesystemIterator::SKIP_DOTS
                | FilesystemIterator::UNIX_PATHS
            );
            $filterIterator = new RecursiveCallbackFilterIterator(
                $directoryIterator,
                function (SplFileInfo $file, string $filepath, RecursiveDirectoryIterator $iterator)
                use ($excludedDirs, $excludedFiles, $excludedDirsRegex, $skipHidden, $skipZip)
                : bool {
                    $filename = $file->getFilename();
                    if ($skipHidden && mb_substr($filename, 0, 1) === '.') {
                        return false;
                    } elseif ($skipZip && in_array($file->getExtension(), ['bz2', 'tar', 'gz', 'xz', 'zip'])) {
                        return false;
                    } elseif ($file->isFile()) {
                        return $file->isReadable()
                            && !in_array($filepath, $excludedFiles);
                     } elseif ($file->isDir()) {
                        return $file->isExecutable()
                            && $file->isReadable()
                            && !in_array($filepath, $excludedDirs)
                            // Skip sub dirs, even if automatically managed.
                            && (!$excludedDirsRegex || !preg_match($excludedDirsRegex, $filepath . '/'));
                    } else {
                        return false;
                    }
                }
            );
            $iterator = new RecursiveIteratorIterator($filterIterator, RecursiveIteratorIterator::CHILD_FIRST);

            $this->logger->info(
                'Preparation of the listing of files.' // @translate
            );

            /** @var \SplFileInfo $file */
            $total = 0;
            foreach ($iterator as $filepath => $file) {
                $relativePath = mb_substr($filepath, $sourceLength + 1);
                ++$total;
                if ($file->isDir()) {
                    ++$result['total_dirs'];
                    $zip->addEmptyDir($relativePath);
                } else {
                    ++$result['total_files'];
                    $result['total_size'] += $file->getSize();
                    $zip->addFile($filepath, $relativePath);
                }
                $zip->setCompressionName($relativePath, $compressionName, $compression < 0 ? 6 : $compression);
                ++$result['total'];
                // It is useless to log info, because the zip is created during
                // call to close().
                if (($result['total'] % 10000) === 0) {
                    if ($this->shouldStop()) {
                        $this->logger->notice(
                            'Backup stopped: {total_dirs} dirs, {total_files} files, size: {total_size} bytes prepared.', // @translate
                            [
                                'total_dirs' => number_format((int) $result['total_dirs'], 0, ',', ' '),
                                'total_files' => number_format((int) $result['total_files'], 0, ',', ' '),
                                'total_size' => number_format((int) $result['total_size'], 0, ',', ' '),
                            ]
                        );
                        $zip->close();
                        @unlink($destination);
                        $result['size'] = null;
                        return $result;
                    }
                }
            }

            $this->logger->info(
                'Backup to process: {total_dirs} dirs, {total_files} files, size: {total_size} bytes.', // @translate
                [
                    'total_dirs' => number_format((int) $result['total_dirs'], 0, ',', ' '),
                    'total_files' => number_format((int) $result['total_files'], 0, ',', ' '),
                    'total_size' => number_format((int) $result['total_size'], 0, ',', ' '),
                ]
            );

            if (method_exists($zip, 'registerCancelCallback')) {
                $zip->registerCancelCallback(function () {
                    return $this->shouldStop() ? -1 : 0;
                });

                $zip->registerProgressCallback(0.1, function ($rate) {
                    $this->logger->info(
                        'Backup in progress: {percent}', // @translate
                        ['percent' => $rate * 100]
                    );
                });
            }

            // Zip the file.
            $resultZip = $zip->close();
            if (!$resultZip) {
                $this->logger->err(
                    'An error occurred during finalization of the zip archive.' // @translate
                );
                $result['error'] = true;
            } else {
                $result['size'] = filesize($destination);
            }

            return $result;
        }

        // Via zip on cli.
        $excludedDirs = [];
        $excludedFiles = [];
        foreach ($exclude as $excluded) {
            if (mb_substr($excluded, -1) === '/') {
                $excludedDirs[] = $excluded . '*';
            } else {
                $excludedFiles[] = $excluded;
            }
        }

        $excludeDirs = array_map('escapeshellarg', $excludedDirs);
        $excludeFiles = array_map('escapeshellarg', $excludedFiles);
        $excluded = array_merge($excludeDirs, $excludeFiles);

        // Create the zip.
        // The default compression level is 6.
        $cd = 'cd ' . escapeshellarg($source);
        $cmd = $cd
            . ' && ' . $this->zipGenerator
            . ' --quiet -X'
            . ' --recurse-paths '
            . ($compression < 0 ? '' : " -$compression")
            . ($excluded ? ' --exclude ' . implode(' ', $excluded) : '')
            . escapeshellarg($destination) . ' ' . escapeshellarg('.');

        $resultZip = $this->cli->execute($cmd);
        if ($resultZip === false) {
            $this->logger->err(
                'An error occurred during preparation of the zip archive.' // @translate
            );
            $result['error'] = true;
        } else {
            $result['size'] = filesize($destination);
        }

        return $result;
    }
}

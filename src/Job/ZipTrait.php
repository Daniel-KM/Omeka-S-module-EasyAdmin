<?php declare(strict_types=1);

namespace EasyAdmin\Job;

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
     * @return int Number of compressed files (-1 if undetermined, 0 if issue).
     */
    protected function zip(
        string $source,
        string $destination,
        array $exclude = [],
        int $compression = -1
    ): int {
        if ($compression > 9) {
            $compression = 9;
        } elseif ($compression < 0) {
            $compression = -1;
        }

        $isCli = $this->zipGenerator !== 'ZipArchive';
        $expand = $isCli ? '*' : '';

        $excludedDirs = [];
        $excludedFiles = [];
        foreach ($exclude as $excluded) {
            if (mb_substr($excluded, -1) === '/') {
                $excludedDirs[] = $excluded . $expand;
            } else {
                $excludedFiles[] = $excluded;
            }
        }

        if ($this->zipGenerator === 'ZipArchive') {
            // Create the zip.
            $zip = new ZipArchive();
            if ($zip->open($destination, ZipArchive::CREATE) !== true) {
                return 0;
            }

            // Add all files.
            $files = $this->recursiveGlob($source . DIRECTORY_SEPARATOR . '*');
            if (empty($files)) {
                return 0;
            }

            $skipHidden = in_array('.*', $exclude);
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

            foreach ($files as $file) {
                if (in_array($file, $excludedFiles)) {
                    continue;
                }
                $filename = basename($file);
                if ($skipHidden && mb_substr($filename, 0, 1) === '.') {
                    continue;
                }
                $relativePath = mb_substr($file, $sourceLength + 1);
                foreach ($excludedDirs as $excluded) {

                }
                if (in_array($relativePath, $exclude)) {
                    continue;
                }
                if (mb_strpos($relativePath, $excludedDirs)) {

                }
                if (is_dir($file)) {
                    $result = $zip->addEmptyDir($relativePath);
                } else {
                    $result = $zip->addFile($file, $relativePath);
                }
                $zip->setCompressionName($relativePath, $compressionName, $compression < 0 ? 6 : $compression);
            }

            // Zip the file.
            $result = $zip->close();

            return empty($result) ? 0 : count($files);
        }

        // Via zip on cli.
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
        $result = $this->cli->execute($cmd);
        return $result === false ? 0 : -1;
    }

    /**
     * Get all dirs and files of a directory, recursively, via glob().
     *
     * Does not support flag GLOB_BRACE
     */
    protected function recursiveGlob($pattern, $flags = 0): array
    {
        if (empty($pattern)) {
            return [];
        }

        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, $this->recursiveGlob($dir . DIRECTORY_SEPARATOR . basename($pattern), $flags));
        }

        return $files;
    }
}

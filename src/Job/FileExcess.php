<?php
namespace BulkCheck\Job;

class FileExcess extends AbstractCheckFile
{
    public function perform()
    {
        parent::perform();

        $process = $this->getArg('process');

        if ($process === 'files_excess_move' && !$this->createDir($this->basePath . '/check')) {
            $this->logger->err(
                'Unable to prepare directory "{path}". Check rights.', // @translate
                ['path' => '/files/check']
            );
            return;
        }

        $this->checkExcessFiles($process === 'files_excess_move');

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
                $media = $this->mediaRepository->findOneBy([
                    'storageId' => $storageId,
                    'extension' => $extension,
                    'hasOriginal' => 1,
                ]);
            } else {
                // The extension of the original file is unknown, but it doesn't
                // matter, since each filepath is unique.
                $storageId = substr($filename, 0, strrpos($filename, '.'));
                $media = $this->mediaRepository->findOneBy([
                    'storageId' => $storageId,
                    'hasThumbnails' => 1,
                ]);
            }

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
                'End check of {total} files for type {type}: {total_excess} files in excess moved.', // @translate
                ['total' => count($files), 'type' => $type, 'total_excess' => $totalExcess]
            );
        } else {
            $this->logger->notice(
                'End check of {total} files for type {type}: {total_excess} files in excess.', // @translate
                ['total' => count($files), 'type' => $type, 'total_excess' => $totalExcess]
            );
        }

        return true;
    }

    protected function createDir($path)
    {
        return file_exists($path)
            ? (is_dir($path) ? is_writeable($path) : false)
            : @mkdir($path, 0775, true);
    }
}

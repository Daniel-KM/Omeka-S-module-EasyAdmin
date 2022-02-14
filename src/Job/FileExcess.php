<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileExcess extends AbstractCheckFile
{
    protected $columns = [
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate
        'type' => 'Type', // @translate
        'exists' => 'Exists', // @translate
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

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

        $this->finalizeOutput();
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

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate

        $this->logger->notice(
            'Starting check of {total} files for type {type}.', // @translate
            ['total' => $total, 'type' => $type]
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

            $row = [
                'filename' => $filename,
                'extension' => pathinfo($filename, PATHINFO_EXTENSION),
                'type' => $type,
                'exists' => '',
                'item' => '',
                'media' => '',
                'fixed' => '',
            ];

            if ($media) {
                $row['exists'] = $yes;
                $row['item'] = $media->getItem()->getId();
                $row['media'] = $media->getId();
                ++$totalSuccess;
                $this->entityManager->clear();
                $this->writeRow($row);
                continue;
            }

            $row['exists'] = $no;

            if ($move) {
                // Creation of a folder is required for module ArchiveRepertory
                // or some other ones.
                $dirname = dirname($movePath . '/' . $filename);
                if ($dirname !== $movePath) {
                    if (!$this->createDir($dirname)) {
                        $row['fixed'] = $no;
                        $this->writeRow($row);
                        $this->logger->err(
                            'Unable to prepare directory "{path}". Check rights.', // @translate
                            ['path' => '/files/check/' . $type . '/' . $filename]
                        );
                        return false;
                    }
                }
                $result = @rename($path . '/' . $filename, $movePath . '/' . $filename);
                if ($result) {
                    $row['fixed'] = $yes;
                    $this->logger->warn(
                        'File "{filename}" ("{type}", {processed}/{total}) doesn’t exist in database and was moved.', // @translate
                        ['filename' => $filename, 'type' => $type, 'processed' => $i, 'total' => $total]
                    );
                } else {
                    $row['fixed'] = $no;
                    $this->writeRow($row);
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

            $this->writeRow($row);

            ++$totalExcess;
            $this->entityManager->clear();
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
}

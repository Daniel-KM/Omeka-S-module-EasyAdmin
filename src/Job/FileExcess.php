<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Doctrine\DBAL\Connection;

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

        // Use generator to avoid loading all files in memory.
        $filesIterator = $this->iterateFilesInFolder($path);

        $totalSuccess = 0;
        $totalExcess = 0;
        $totalProcessed = 0;

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate

        $this->logger->notice(
            'Starting check of files for type {type}.', // @translate
            ['type' => $type]
        );

        $connection = $this->entityManager->getConnection();

        // Process files in batches to reduce database round-trips.
        // Instead of one query per file (N+1), batch 500 files into one
        // IN() query, reducing queries by ~500x.
        $batchSize = 500;
        $batch = [];

        foreach ($filesIterator as $filename) {
            $batch[] = $filename;
            if (count($batch) < $batchSize) {
                continue;
            }
            $result = $this->processExcessBatch(
                $batch, $type, $isOriginal, $path, $move, $movePath ?? '', $connection,
                $yes, $no, $totalProcessed, $totalSuccess, $totalExcess
            );
            $batch = [];
            if ($result === false) {
                return false;
            }
        }

        // Process remaining files.
        if ($batch) {
            $result = $this->processExcessBatch(
                $batch, $type, $isOriginal, $path, $move, $movePath ?? '', $connection,
                $yes, $no, $totalProcessed, $totalSuccess, $totalExcess
            );
            if ($result === false) {
                return false;
            }
        }

        if ($move) {
            $this->logger->notice(
                'End check of {total} files for type {type}: {total_excess} files in excess moved.', // @translate
                ['total' => $totalProcessed, 'type' => $type, 'total_excess' => $totalExcess]
            );
        } else {
            $this->logger->notice(
                'End check of {total} files for type {type}: {total_excess} files in excess.', // @translate
                ['total' => $totalProcessed, 'type' => $type, 'total_excess' => $totalExcess]
            );
        }

        return true;
    }

    /**
     * Process a batch of files, checking existence in database with one query.
     *
     * @return bool|null False to stop processing, null otherwise.
     */
    protected function processExcessBatch(
        array $filenames,
        string $type,
        bool $isOriginal,
        string $path,
        bool $move,
        string $movePath,
        \Doctrine\DBAL\Connection $connection,
        string $yes,
        string $no,
        int &$totalProcessed,
        int &$totalSuccess,
        int &$totalExcess
    ) {
        // Build lookup: storageId => [filename, extension].
        $storageMap = [];
        foreach ($filenames as $filename) {
            if ($isOriginal) {
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $storageId = strlen($ext)
                    ? substr($filename, 0, strrpos($filename, '.'))
                    : $filename;
            } else {
                $ext = null;
                $storageId = substr($filename, 0, strrpos($filename, '.'));
            }
            $storageMap[$filename] = ['storageId' => $storageId, 'extension' => $ext];
        }

        $storageIds = array_unique(array_column($storageMap, 'storageId'));

        // Single batched query instead of N individual queries. Include
        // digital_object rows when the module DigitalObject is active so its
        // files are not flagged as excess.
        $hasDigitalObject = class_exists('DigitalObject\Module', false);
        if ($isOriginal) {
            $sql = 'SELECT `id`, `item_id`, `storage_id`, `extension` FROM `media`'
                . ' WHERE `storage_id` IN (:ids) AND `has_original` = 1';
            if ($hasDigitalObject) {
                $sql .= ' UNION ALL SELECT `id`, NULL AS `item_id`, `storage_id`, `extension`'
                    . ' FROM `digital_object` WHERE `storage_id` IN (:ids) AND `has_original` = 1';
            }
        } else {
            $sql = 'SELECT `id`, `item_id`, `storage_id` FROM `media`'
                . ' WHERE `storage_id` IN (:ids) AND `has_thumbnails` = 1';
            if ($hasDigitalObject) {
                $sql .= ' UNION ALL SELECT `id`, NULL AS `item_id`, `storage_id`'
                    . ' FROM `digital_object` WHERE `storage_id` IN (:ids) AND `has_thumbnails` = 1';
            }
        }
        $stmt = $connection->executeQuery($sql, ['ids' => $storageIds], ['ids' => Connection::PARAM_STR_ARRAY]);
        $dbRows = $stmt->fetchAllAssociative();

        // Build lookup: "storageId|extension" => media row (for originals)
        // or "storageId" => media row (for derivatives).
        $mediaLookup = [];
        foreach ($dbRows as $dbRow) {
            if ($isOriginal) {
                $key = $dbRow['storage_id'] . '|' . ($dbRow['extension'] ?? '');
            } else {
                $key = $dbRow['storage_id'];
            }
            $mediaLookup[$key] = $dbRow;
        }

        // Process each file in the batch.
        foreach ($filenames as $filename) {
            if (($totalProcessed % 500 === 0) && $totalProcessed) {
                $this->logger->info(
                    '{processed} files processed.', // @translate
                    ['processed' => $totalProcessed]
                );
                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job was stopped.' // @translate
                    );
                    return false;
                }
            }
            ++$totalProcessed;

            $info = $storageMap[$filename];
            if ($isOriginal) {
                $key = $info['storageId'] . '|' . ($info['extension'] ?? '');
            } else {
                $key = $info['storageId'];
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

            if (isset($mediaLookup[$key])) {
                $row['exists'] = $yes;
                $row['item'] = $mediaLookup[$key]['item_id'];
                $row['media'] = $mediaLookup[$key]['id'];
                ++$totalSuccess;
                $this->writeRow($row);
                continue;
            }

            $row['exists'] = $no;

            if ($move) {
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
                        "File \"{filename}\" (\"{type}\", #{processed}) doesn't exist in database and was moved.", // @translate
                        ['filename' => $filename, 'type' => $type, 'processed' => $totalProcessed]
                    );
                } else {
                    $row['fixed'] = $no;
                    $this->writeRow($row);
                    $this->logger->err(
                        "File \"{filename}\" (type \"{type}\") doesn't exist in database, and cannot be moved.", // @translate
                        ['filename' => $filename, 'type' => $type]
                    );
                    return false;
                }
            } else {
                $this->logger->warn(
                    "File \"{filename}\" (\"{type}\", #{processed}) doesn't exist in database.", // @translate
                    ['filename' => $filename, 'type' => $type, 'processed' => $totalProcessed]
                );
            }

            $this->writeRow($row);
            ++$totalExcess;
        }

        return null;
    }
}

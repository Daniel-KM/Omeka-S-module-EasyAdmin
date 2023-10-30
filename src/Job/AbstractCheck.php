<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Omeka\Job\AbstractJob;

/**
 * Logging copied in BulkImport.
 * @see \BulkImport\Processor\CheckTrait
 */
abstract class AbstractCheck extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 100;

    /**
     * Max number of rows to output in spreadsheet.
     * @var integer
     */
    const SPREADSHEET_ROW_LIMIT = 1000000;

    /**
     * @var \Laminas\Log\Logger
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
     * @var \Doctrine\DBAL\Connection
     */
    protected $dbalConnection;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $ormConnection;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $resourceRepository;

        /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $itemRepository;

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

    /**
     * @var string
     */
    protected $filepath;

    /**
     * @var resource|false
     */
    protected $handle;

    /**
     * Default options for output (tsv).
     *
     * @var array
     */
    protected $options = [
        'delimiter' => "\t",
        'enclosure' => 0,
        'escape' => 0,
    ];

    /**
     * List of columns keys and labels for the output spreadsheet.
     *
     * @var array
     */
    protected $columns = [];

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('easy-admin/check/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);
        $this->api = $services->get('ControllerPluginManager')->get('api');
        $this->entityManager = $services->get('Omeka\EntityManager');
        // These two connections are not the same in doctrine.
        $this->dbalConnection = $services->get('Omeka\Connection');
        $this->ormConnection = $this->entityManager->getConnection();
        $this->connection = $this->dbalConnection;
        $this->resourceRepository = $this->entityManager->getRepository(\Omeka\Entity\Resource::class);
        $this->itemRepository = $this->entityManager->getRepository(\Omeka\Entity\Item::class);
        $this->mediaRepository = $this->entityManager->getRepository(\Omeka\Entity\Media::class);
        $this->config = $services->get('Config');
        $this->basePath = $this->config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $this->initializeOutput();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $this->logger->notice(
            'Starting "{process}".', // @translate
            ['process' => $process]
        );
    }

    /**
     * Prepare an output file.
     *
     * @todo Use a temporary file and copy result at the end of the process.
     *
     * @return self
     */
    protected function initializeOutput()
    {
        if (empty($this->columns)) {
            return $this;
        }

        $this->prepareFilename();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return $this;
        }

        $this->handle = fopen($this->filepath, 'w+');
        if (!$this->handle) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Unable to open output: {error}.', // @translate
                ['error' => error_get_last()['message']]
            );
            return $this;
        }

        // Prepend the utf-8 bom.
        fwrite($this->handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($this->options['enclosure'] === 0) {
            $this->options['enclosure'] = chr(0);
        }
        if ($this->options['escape'] === 0) {
            $this->options['escape'] = chr(0);
        }

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $row = [];
        foreach ($this->columns as $column) {
            $row[] = $translator->translate($column);
        }
        $this->writeRow($row);

        return $this;
    }

    /**
     * Fill a row (tsv) in the output file.
     */
    protected function writeRow(array $row): \EasyAdmin\Job\AbstractCheck
    {
        static $columnKeys;
        static $total = 0;
        static $skipNext = false;

        ++$total;
        if ($total > self::SPREADSHEET_ROW_LIMIT) {
            if ($skipNext) {
                return $this;
            }
            $skipNext = true;
            $this->logger->err(
                'Trying to output more than %d messages. Next messages are skipped.', // @translate
                self::SPREADSHEET_ROW_LIMIT
            );
            return $this;
        }

        if (is_null($columnKeys)) {
            $columnKeys = array_fill_keys(array_keys($this->columns), null);
        }

        // Order row according to the columns when associative array.
        if (array_values($row) !== $row) {
            $row = array_replace($columnKeys, array_intersect_key($row, $columnKeys));
        }

        fputcsv($this->handle, $row, $this->options['delimiter'], $this->options['enclosure'], $this->options['escape']);
        return $this;
    }

    /**
     * Finalize the output file. The output file is removed in case of error.
     *
     * @return self
     */
    protected function finalizeOutput()
    {
        if (empty($this->columns)) {
            return $this;
        }

        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            if ($this->handle) {
                fclose($this->handle);
                @unlink($this->filepath);
            }
            return $this;
        }

        if (!$this->handle) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }
        fclose($this->handle);
        $this->messageResultFile();
        return $this;
    }

    /**
     * Add a  message with the url to the file.
     *
     * @return self
     */
    protected function messageResultFile()
    {
        $baseUrl = $this->config['file_store']['local']['base_uri'] ?: $this->getServiceLocator()->get('Router')->getBaseUrl() . '/files';
        $this->logger->notice(
            'Results are available in this spreadsheet: {url}.', // @translate
            ['url' => $baseUrl . '/check/' . mb_substr($this->filepath, mb_strlen($this->basePath . '/check/'))]
        );
        return $this;
    }

    /**
     * Create the unique file name compatible on various os.
     *
     * Note: the destination dir is created during install.
     *
     * @return self
     */
    protected function prepareFilename()
    {
        $destinationDir = $this->basePath . '/check';

        $label = $this->getArg('process', '');
        $base = preg_replace('/[^A-Za-z0-9]/', '_', $label);
        $base = $base ? substr(preg_replace('/_+/', '_', $base), 0, 20) . '-' : '';
        $date = (new \DateTime())->format('Ymd-His');
        $extension = 'tsv';

        // Avoid issue on very big base.
        $i = 0;
        do {
            $filename = sprintf('%s%s%s.%s', $base, $date, $i ? '-' . $i : '', $extension);
            $filePath = $destinationDir . '/' . $filename;
            if (!file_exists($filePath)) {
                try {
                    $result = @touch($filePath);
                } catch (\Exception $e) {
                    $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                    $this->logger->err(
                        'Error when saving "{filename}" (temp file: "{tempfile}"): {exception}', // @translate
                        ['filename' => $filename, 'tempfile' => $filePath, 'exception' => $e]
                    );
                    return $this;
                }

                if (!$result) {
                    $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                    $this->logger->err(
                        'Error when saving "{filename}" (temp file: "{tempfile}"): {error}', // @translate
                        ['filename' => $filename, 'tempfile' => $filePath, 'error' => error_get_last()['message']]
                    );
                    return $this;
                }

                break;
            }
        } while (++$i);

        $this->filepath = $filePath;
        return $this;
    }

    /**
     * Check if a module is active.
     *
     * @param string $module
     * @return bool
     */
    protected function isModuleActive(string $module): bool
    {
        $services = $this->getServiceLocator();
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($module);
        return $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir($dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    'The directory "{path}" is not writeable.', // @translate
                    ['path' => $dirPath]
                );
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                'The directory "{path}" is not writeable: {error}.', // @translate
                ['path' => $dirPath, 'error' => error_get_last()['message']]
            );
            return null;
        }

        return $dirPath;
    }
}

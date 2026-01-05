<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Omeka\Job\AbstractJob;

/**
 * Backup the database to a SQL file.
 */
class DatabaseBackup extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Stdlib\Cli
     */
    protected $cli;

    /**
     * @var string
     */
    protected $basePath;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $this->logger = $services->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('easyadmin/backup/db_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);

        $this->cli = $services->get('Omeka\Cli');
        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // Check if mysqldump is available.
        $mysqldump = $this->cli->getCommandPath('mysqldump');
        if (!$mysqldump) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The command "mysqldump" is not available on this server.' // @translate
            );
            return;
        }

        // Get database configuration.
        $dbConfig = $this->getDatabaseConfig();
        if (!$dbConfig) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Unable to read database configuration.' // @translate
            );
            return;
        }

        // Create backup directory.
        $backupDir = $this->basePath . '/backup';
        if (!$this->checkDestinationDir($backupDir)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }

        // Generate filename.
        $compress = (bool) $this->getArg('compress', true);
        $date = (new \DateTime())->format('Ymd-His');
        $filename = sprintf('database-%s.sql%s', $date, $compress ? '.gz' : '');
        $filepath = $backupDir . '/' . $filename;

        // Build mysqldump command.
        $cmd = sprintf(
            '%s --host=%s --user=%s --password=%s %s',
            escapeshellcmd($mysqldump),
            escapeshellarg($dbConfig['host']),
            escapeshellarg($dbConfig['user']),
            escapeshellarg($dbConfig['password']),
            escapeshellarg($dbConfig['dbname'])
        );

        // Add options.
        $cmd .= ' --single-transaction --quick --lock-tables=false';

        // Add compression.
        if ($compress) {
            $gzip = $this->cli->getCommandPath('gzip');
            if ($gzip) {
                $cmd .= ' | ' . escapeshellcmd($gzip);
            } else {
                $this->logger->warn(
                    'Gzip not available, backup will not be compressed.' // @translate
                );
                $filepath = str_replace('.sql.gz', '.sql', $filepath);
            }
        }

        $cmd .= ' > ' . escapeshellarg($filepath);

        $this->logger->info(
            'Starting database backup…' // @translate
        );

        // Execute backup.
        $result = $this->cli->execute($cmd);

        if ($result === false || !file_exists($filepath)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Database backup failed.' // @translate
            );
            return;
        }

        $size = filesize($filepath);
        $store = $services->get('Omeka\File\Store');
        $storagePath = 'backup/' . $filename;
        $fileUrl = $store->getUri($storagePath);

        $this->logger->notice(
            'Database backup completed: {link} (size: {size}).', // @translate
            [
                'link' => sprintf('<a href="%1$s" download="%2$s">%2$s</a>', $fileUrl, $filename),
                'size' => $this->formatSize($size),
            ]
        );
    }

    /**
     * Get database configuration from database.ini.
     */
    protected function getDatabaseConfig(): ?array
    {
        $configFile = OMEKA_PATH . '/config/database.ini';
        if (!file_exists($configFile) || !is_readable($configFile)) {
            return null;
        }

        $config = parse_ini_file($configFile);
        if (!$config || !isset($config['host'], $config['user'], $config['dbname'])) {
            return null;
        }

        return [
            'host' => $config['host'] ?? 'localhost',
            'user' => $config['user'] ?? '',
            'password' => $config['password'] ?? '',
            'dbname' => $config['dbname'] ?? '',
        ];
    }

    /**
     * Check or create destination directory.
     */
    protected function checkDestinationDir(string $dirPath): bool
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_writable($dirPath)) {
                $this->logger->err(
                    'The backup directory is not writable: {path}', // @translate
                    ['path' => $dirPath]
                );
                return false;
            }
            return true;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->logger->err(
                'Unable to create backup directory: {path}', // @translate
                ['path' => $dirPath]
            );
            return false;
        }

        return true;
    }

    /**
     * Format file size for display.
     */
    protected function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

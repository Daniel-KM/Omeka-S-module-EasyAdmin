<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Omeka\Job\AbstractJob;

/**
 * Backup the database to a SQL file.
 *
 * Supports both mysqldump (if available) and pure PHP export (like Adminer).
 */
class DatabaseBackup extends AbstractJob
{
    /**
     * Tables to skip data export (only structure).
     * These tables can be very large and are often not needed for restore.
     */
    protected const TABLES_SKIP_DATA = [
        'session',
        'fulltext_search',
        'job',
        'log',
    ];

    /**
     * Pattern for tables to skip data export.
     */
    protected const TABLES_SKIP_DATA_PATTERNS = [
        'triplestore_',
    ];

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Stdlib\Cli
     */
    protected $cli;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var array
     */
    protected $dbConfig;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $this->logger = $services->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('easyadmin/backup/db_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);

        $this->cli = $services->get('Omeka\Cli');
        $this->connection = $services->get('Omeka\Connection');
        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // Get database configuration.
        $this->dbConfig = $this->getDatabaseConfig();
        if (!$this->dbConfig) {
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

        // Get options.
        $compress = (bool) $this->getArg('compress', true);
        $includeStructure = (bool) $this->getArg('include_structure', true);
        $includeData = (bool) $this->getArg('include_data', true);
        $includeViews = (bool) $this->getArg('include_views', true);
        $includeRoutines = (bool) $this->getArg('include_routines', true);
        $includeTriggers = (bool) $this->getArg('include_triggers', true);
        $skipDataTables = $this->getArg('skip_data_tables', []);

        if (!$includeStructure && !$includeData) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'At least one of structure or data must be included.' // @translate
            );
            return;
        }

        // Generate filename.
        $date = (new \DateTime())->format('Ymd-His');
        $suffix = '';
        if (!$includeStructure) {
            $suffix = '-data';
        } elseif (!$includeData) {
            $suffix = '-structure';
        }
        $filename = sprintf('database-%s%s.sql%s', $date, $suffix, $compress ? '.gz' : '');
        $filepath = $backupDir . '/' . $filename;

        $this->logger->info(
            'Starting database backup…' // @translate
        );

        // Try mysqldump first if available.
        $mysqldump = $this->findCommandPath('mysqldump');
        if ($mysqldump) {
            $success = $this->backupWithMysqldump(
                $filepath,
                $compress,
                $includeStructure,
                $includeData,
                $includeViews,
                $includeRoutines,
                $includeTriggers,
                $skipDataTables
            );
        } else {
            $this->logger->info(
                'mysqldump not available, using PHP export.' // @translate
            );
            $success = $this->backupWithPhp(
                $filepath,
                $compress,
                $includeStructure,
                $includeData,
                $includeViews,
                $includeRoutines,
                $includeTriggers,
                $skipDataTables
            );
        }

        if (!$success) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Database backup failed.' // @translate
            );
            return;
        }

        $filename = basename($filepath);
        // filesize() returns false when the file is missing (unzipped).
        // Keep it integer for formatSize().
        $size = (int) @filesize($filepath);
        // Link to the authenticated download action: the backup directory is
        // protected by a .htaccess that denies direct web access.
        $url = $this->getServiceLocator()->get('ViewHelperManager')->get('url');
        $fileUrl = $url('admin/easy-admin/backup', ['action' => 'download'], ['query' => ['file' => $filename]]);

        $this->logger->notice(
            'Database backup completed: {link} (size: {size}).', // @translate
            [
                'link' => sprintf('<a href="%1$s" download="%2$s">%2$s</a>', $fileUrl, $filename),
                'size' => $this->formatSize($size),
            ]
        );
    }

    /**
     * The gzip compression level flag (" -1" to " -9"), empty for the default.
     */
    protected function gzipLevelFlag(): string
    {
        $level = (int) $this->getArg('compression_level', 6);
        return ($level >= 1 && $level <= 9) ? ' -' . $level : '';
    }

    /**
     * Backup using mysqldump command with shell streaming for performance.
     *
     * Uses shell pipes (mysqldump | gzip > file) for efficient streaming
     * without loading data into memory.
     */
    protected function backupWithMysqldump(
        string &$filepath,
        bool $compress,
        bool $includeStructure,
        bool $includeData,
        bool $includeViews,
        bool $includeRoutines,
        bool $includeTriggers,
        array $skipDataTables
    ): bool {
        $mysqldump = $this->findCommandPath('mysqldump');

        // Handle tables to skip data.
        $skipTables = $this->getSkipDataTables($skipDataTables);
        if ($includeData && $skipTables) {
            return $this->backupWithMysqldumpSplit(
                $filepath,
                $compress,
                $includeStructure,
                $includeViews,
                $includeRoutines,
                $includeTriggers,
                $skipTables
            );
        }

        // Use --defaults-extra-file to avoid exposing password in process list.
        $defaultsFile = $this->createMysqlDefaultsFile();
        if (!$defaultsFile) {
            return false;
        }

        // Build mysqldump command.
        $cmd = sprintf(
            '%s --defaults-extra-file=%s --host=%s',
            escapeshellcmd($mysqldump),
            escapeshellarg($defaultsFile),
            escapeshellarg($this->dbConfig['host'])
        );

        $cmd .= ' --single-transaction --quick --lock-tables=false';
        $cmd .= ' --set-charset --default-character-set=utf8mb4';

        if (!$includeStructure) {
            $cmd .= ' --no-create-info';
        }

        if (!$includeData) {
            $cmd .= ' --no-data';
        }

        if ($includeRoutines) {
            $cmd .= ' --routines';
        }

        if ($includeTriggers) {
            $cmd .= ' --triggers';
        } else {
            $cmd .= ' --skip-triggers';
        }

        $cmd .= ' ' . escapeshellarg($this->dbConfig['dbname']);

        // Add compression via shell pipe for streaming efficiency.
        if ($compress) {
            $gzip = $this->findCommandPath('gzip');
            if ($gzip) {
                $cmd .= ' | ' . escapeshellcmd($gzip) . ' -c' . $this->gzipLevelFlag();
            } else {
                $this->logger->warn('gzip not available, backup will not be compressed.'); // @translate
                $filepath = str_replace('.sql.gz', '.sql', $filepath);
            }
        }

        // Redirect to file.
        $cmd .= ' > ' . escapeshellarg($filepath);

        // Execute command.
        $result = $this->cli->execute($cmd);

        @unlink($defaultsFile);

        return $result !== false && file_exists($filepath) && filesize($filepath) > 0;
    }

    /**
     * Backup with mysqldump, splitting tables that skip data.
     *
     * Uses shell pipes for streaming efficiency.
     */
    protected function backupWithMysqldumpSplit(
        string &$filepath,
        bool $compress,
        bool $includeStructure,
        bool $includeViews,
        bool $includeRoutines,
        bool $includeTriggers,
        array $skipTables
    ): bool {
        $mysqldump = $this->findCommandPath('mysqldump');
        $tempFile = $filepath . '.tmp.sql';

        // Get all tables.
        $allTables = $this->getTables();
        $regularTables = array_diff($allTables, $skipTables);

        // Use --defaults-extra-file to avoid exposing password in process list.
        $defaultsFile = $this->createMysqlDefaultsFile();
        if (!$defaultsFile) {
            return false;
        }

        // Base command options.
        $baseCmd = sprintf(
            '%s --defaults-extra-file=%s --host=%s',
            escapeshellcmd($mysqldump),
            escapeshellarg($defaultsFile),
            escapeshellarg($this->dbConfig['host'])
        );
        $baseCmd .= ' --single-transaction --quick --lock-tables=false';
        $baseCmd .= ' --set-charset --default-character-set=utf8mb4';

        // Dump regular tables (with data).
        $cmd = $baseCmd;
        if ($includeRoutines) {
            $cmd .= ' --routines';
        }
        if ($includeTriggers) {
            $cmd .= ' --triggers';
        } else {
            $cmd .= ' --skip-triggers';
        }
        $cmd .= ' ' . escapeshellarg($this->dbConfig['dbname']);
        foreach ($regularTables as $table) {
            $cmd .= ' ' . escapeshellarg($table);
        }
        $cmd .= ' > ' . escapeshellarg($tempFile);

        try {
            $result = $this->cli->execute($cmd);
            if ($result === false) {
                @unlink($tempFile);
                return false;
            }

            // Append structure-only for skip tables.
            if ($includeStructure && $skipTables) {
                $cmd = $baseCmd . ' --no-data --skip-triggers';
                $cmd .= ' ' . escapeshellarg($this->dbConfig['dbname']);
                foreach ($skipTables as $table) {
                    $cmd .= ' ' . escapeshellarg($table);
                }
                $cmd .= ' >> ' . escapeshellarg($tempFile);

                $result = $this->cli->execute($cmd);
                if ($result === false) {
                    @unlink($tempFile);
                    return false;
                }
            }

            // Compress if needed using shell pipe.
            if ($compress) {
                $gzip = $this->findCommandPath('gzip');
                if ($gzip) {
                    $cmd = escapeshellcmd($gzip) . ' -c' . $this->gzipLevelFlag() . ' ' . escapeshellarg($tempFile)
                        . ' > ' . escapeshellarg($filepath);
                    $result = $this->cli->execute($cmd);
                    @unlink($tempFile);
                    return $result !== false && file_exists($filepath) && filesize($filepath) > 0;
                }
                // No gzip: just rename.
                $filepath = str_replace('.sql.gz', '.sql', $filepath);
            }

            rename($tempFile, $filepath);
            return file_exists($filepath) && filesize($filepath) > 0;
        } finally {
            @unlink($defaultsFile);
        }
    }

    /**
     * Backup using pure PHP (like Adminer).
     */
    protected function backupWithPhp(
        string $filepath,
        bool $compress,
        bool $includeStructure,
        bool $includeData,
        bool $includeViews,
        bool $includeRoutines,
        bool $includeTriggers,
        array $skipDataTables
    ): bool {
        $skipTables = $this->getSkipDataTables($skipDataTables);

        // Open file for writing.
        if ($compress) {
            $handle = gzopen($filepath, 'wb9');
            if (!$handle) {
                return false;
            }
            $write = function ($data) use ($handle) {
                gzwrite($handle, $data);
            };
            $close = function () use ($handle) {
                gzclose($handle);
            };
        } else {
            $handle = fopen($filepath, 'w');
            if (!$handle) {
                return false;
            }
            $write = function ($data) use ($handle) {
                fwrite($handle, $data);
            };
            $close = function () use ($handle) {
                fclose($handle);
            };
        }

        try {
            // Write header.
            $write("-- EasyAdmin Database Backup\n");
            $write("-- Date: " . date('Y-m-d H:i:s') . "\n");
            $serverVersion = $this->connection->executeQuery('SELECT VERSION()')->fetchOne();
            $write("-- Server: " . ($serverVersion ?: 'unknown') . "\n");
            $write("-- Database: " . $this->dbConfig['dbname'] . "\n\n");

            $write("SET NAMES utf8mb4;\n");
            $write("SET time_zone = '+00:00';\n");
            $write("SET foreign_key_checks = 0;\n");
            $write("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            // Export routines first.
            if ($includeRoutines) {
                $this->exportRoutines($write);
            }

            // Get tables and views.
            $tables = $this->getTables();
            $views = $includeViews ? $this->getViews() : [];

            // Export tables.
            foreach ($tables as $table) {
                // Check for stop signal.
                if ($this->shouldStop()) {
                    $close();
                    @unlink($filepath);
                    $this->logger->warn('Backup stopped by user.'); // @translate
                    return false;
                }

                $skipData = in_array($table, $skipTables);

                if ($includeStructure) {
                    $this->exportTableStructure($table, $write);
                }

                if ($includeData && !$skipData) {
                    $this->exportTableData($table, $write);
                }

                if ($includeTriggers) {
                    $this->exportTriggers($table, $write);
                }
            }

            // Export views.
            foreach ($views as $view) {
                if ($includeStructure) {
                    $this->exportViewStructure($view, $write);
                }
            }

            // Write footer.
            $write("\nSET foreign_key_checks = 1;\n");

            $close();
            return true;
        } catch (\Throwable $e) {
            $close();
            @unlink($filepath);
            $this->logger->err(
                'PHP export failed: {error}', // @translate
                ['error' => $e->getMessage()]
            );
            return false;
        }
    }

    /**
     * Export table structure.
     */
    protected function exportTableStructure(string $table, callable $write): void
    {
        $qi = $this->quoteIdentifier($table);
        $write("--\n-- Table structure for $qi\n--\n\n");
        $write("DROP TABLE IF EXISTS $qi;\n");

        $result = $this->connection->executeQuery("SHOW CREATE TABLE $qi");
        $row = $result->fetchAssociative();
        if ($row) {
            $createSql = $row['Create Table'] ?? '';
            // Remove AUTO_INCREMENT value for cleaner dump.
            $createSql = preg_replace('~ AUTO_INCREMENT=\d+~', '', $createSql);
            $write($createSql . ";\n\n");
        }
    }

    /**
     * Export table data using streaming (like Adminer).
     *
     * Uses unbuffered query to stream data without loading all into memory,
     * and writes in batches for efficiency.
     */
    protected function exportTableData(string $table, callable $write): void
    {
        $qi = $this->quoteIdentifier($table);

        // Get native PDO connection for unbuffered query.
        // Use getWrappedConnection() for Doctrine DBAL 2.x compatibility.
        $pdo = $this->connection->getWrappedConnection();

        // Check if table has data (quick check).
        $countStmt = $pdo->query("SELECT 1 FROM $qi LIMIT 1");
        if (!$countStmt->fetch()) {
            return;
        }

        $write("--\n-- Data for $qi\n--\n\n");

        // Get columns.
        $columnsStmt = $pdo->query("SHOW COLUMNS FROM $qi");
        $columns = [];
        while ($col = $columnsStmt->fetch(\PDO::FETCH_ASSOC)) {
            $columns[] = $col['Field'];
        }
        $columnList = '`' . implode('`, `', $columns) . '`';

        // Disable keys for faster import.
        $write("/*!40000 ALTER TABLE $qi DISABLE KEYS */;\n");

        // Use unbuffered query to stream results.
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $stmt = $pdo->query("SELECT * FROM $qi");

            $rowCount = 0;
            $values = [];
            $maxRowsPerInsert = 500; // Larger batches for speed

            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $rowValues = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $rowValues[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $rowValues[] = $value;
                    } elseif (is_numeric($value) && strpos($value, '0') !== 0 && strlen($value) < 20) {
                        // Numeric string that doesn't start with 0 and isn't too long.
                        $rowValues[] = $value;
                    } else {
                        $rowValues[] = $pdo->quote($value);
                    }
                }
                $values[] = '(' . implode(',', $rowValues) . ')';
                $rowCount++;

                // Write batch.
                if (count($values) >= $maxRowsPerInsert) {
                    $write("INSERT INTO $qi ($columnList) VALUES\n");
                    $write(implode(",\n", $values) . ";\n");
                    $values = [];
                }
            }

            // Write remaining rows.
            if ($values) {
                $write("INSERT INTO $qi ($columnList) VALUES\n");
                $write(implode(",\n", $values) . ";\n");
            }

            $stmt->closeCursor();
        } finally {
            // Restore buffered mode.
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        // Re-enable keys.
        $write("/*!40000 ALTER TABLE $qi ENABLE KEYS */;\n");

        // Log after unbuffered query is done.
        if ($rowCount > 0) {
            $this->logger->info(
                'Exported table {table}: {count} rows.', // @translate
                ['table' => $table, 'count' => number_format($rowCount)]
            );
        }

        $write("\n");
    }

    /**
     * Export view structure.
     */
    protected function exportViewStructure(string $view, callable $write): void
    {
        $qi = $this->quoteIdentifier($view);
        $write("--\n-- View structure for $qi\n--\n\n");
        $write("DROP VIEW IF EXISTS $qi;\n");

        $result = $this->connection->executeQuery("SHOW CREATE VIEW $qi");
        $row = $result->fetchAssociative();
        if ($row) {
            $createSql = $row['Create View'] ?? '';
            $createSql = $this->normalizeDefiner($createSql);
            $write($createSql . ";\n\n");
        }
    }

    /**
     * Normalize DEFINER to CURRENT_USER for portable dumps.
     *
     * DEFINER specifies which user the object runs as. When importing
     * to a different server, the original user may not exist.
     * CURRENT_USER means "the user doing the import".
     */
    protected function normalizeDefiner(string $sql): string
    {
        // Replace DEFINER=`user`@`host` with DEFINER=CURRENT_USER
        // Handles both /*!50013 ... */ comments and plain format
        $sql = preg_replace(
            '~DEFINER\s*=\s*`[^`]+`@`[^`]+`~i',
            'DEFINER=CURRENT_USER',
            $sql
        );
        // Also handle non-quoted format: DEFINER=user@host
        $sql = preg_replace(
            '~DEFINER\s*=\s*[^\s`]+@[^\s`\*]+~i',
            'DEFINER=CURRENT_USER',
            $sql
        );
        return $sql;
    }

    /**
     * Export triggers for a table.
     */
    protected function exportTriggers(string $table, callable $write): void
    {
        $result = $this->connection->executeQuery(
            "SHOW TRIGGERS WHERE `Table` = ?",
            [$table]
        );

        $triggers = $result->fetchAllAssociative();
        if (!$triggers) {
            return;
        }

        $qi = $this->quoteIdentifier($table);
        $write("--\n-- Triggers for $qi\n--\n\n");
        $write("DELIMITER ;;\n");

        foreach ($triggers as $trigger) {
            $triggerName = $this->quoteIdentifier($trigger['Trigger']);
            $write("CREATE TRIGGER $triggerName {$trigger['Timing']} {$trigger['Event']} ON $qi FOR EACH ROW\n");
            $write("{$trigger['Statement']};;\n");
        }

        $write("DELIMITER ;\n\n");
    }

    /**
     * Export routines (procedures and functions).
     */
    protected function exportRoutines(callable $write): void
    {
        $dbname = $this->dbConfig['dbname'];

        // Export procedures.
        $result = $this->connection->executeQuery(
            "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?",
            [$dbname]
        );

        $routines = $result->fetchAllAssociative();
        if (!$routines) {
            return;
        }

        $write("--\n-- Routines\n--\n\n");
        $write("DELIMITER ;;\n");

        foreach ($routines as $routine) {
            $name = $routine['ROUTINE_NAME'];
            $type = $routine['ROUTINE_TYPE'];
            $qiName = $this->quoteIdentifier($name);

            $createResult = $this->connection->executeQuery("SHOW CREATE $type $qiName");
            $createRow = $createResult->fetchAssociative();

            if ($createRow) {
                $key = $type === 'PROCEDURE' ? 'Create Procedure' : 'Create Function';
                $createSql = $createRow[$key] ?? '';
                // Normalize DEFINER for portability.
                $createSql = $this->normalizeDefiner($createSql);
                $write("DROP $type IF EXISTS $qiName;;\n");
                $write($createSql . ";;\n\n");
            }
        }

        $write("DELIMITER ;\n\n");
    }

    /**
     * Quote a SQL identifier (table/column name) with backticks.
     *
     * Escapes any backtick inside the identifier by doubling it.
     */
    protected function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Get list of tables (excluding views).
     */
    protected function getTables(): array
    {
        $result = $this->connection->executeQuery(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            [$this->dbConfig['dbname']]
        );
        return $result->fetchFirstColumn();
    }

    /**
     * Get list of views.
     */
    protected function getViews(): array
    {
        $result = $this->connection->executeQuery(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME",
            [$this->dbConfig['dbname']]
        );
        return $result->fetchFirstColumn();
    }

    /**
     * Get tables to skip data export.
     */
    protected function getSkipDataTables(array $userSkipTables): array
    {
        $skipTables = array_merge(self::TABLES_SKIP_DATA, $userSkipTables);
        $allTables = $this->getTables();

        $result = [];
        foreach ($allTables as $table) {
            // Check exact match.
            if (in_array($table, $skipTables)) {
                $result[] = $table;
                continue;
            }

            // Check patterns.
            foreach (self::TABLES_SKIP_DATA_PATTERNS as $pattern) {
                if (strpos($table, $pattern) === 0) {
                    $result[] = $table;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Create a temporary MySQL defaults file to pass credentials securely.
     *
     * Using --defaults-extra-file avoids exposing the password in the process
     * list (visible via ps aux or /proc).
     */
    protected function createMysqlDefaultsFile(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'omk_mysql_');
        if (!$path) {
            $this->logger->err(
                'Unable to create temporary file for database credentials.' // @translate
            );
            return null;
        }

        $content = sprintf(
            "[client]\nuser=%s\npassword=%s\n",
            addcslashes($this->dbConfig['user'], "\\\"'\n"),
            addcslashes($this->dbConfig['password'], "\\\"'\n")
        );

        // Restrict permissions before writing credentials.
        chmod($path, 0600);
        file_put_contents($path, $content);

        return $path;
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
     * Check or create destination directory, protected from a direct web access.
     *
     * The backups contain sensitive data (database credentials, user passwords,
     * api keys).
     */
    protected function checkDestinationDir(string $dirPath): bool
    {
        return (bool) $this->getServiceLocator()->get('Common\DirectoryManager')
            ->checkDestinationDir($dirPath, true);
    }

    /**
     * Find the path of a command without logging an error when it is absent.
     *
     * Omeka\Stdlib\Cli::getCommandPath() runs "command -v", which exits
     * non-zero for a missing command; the Cli service then logs it as an error
     * (plus an empty stderr line). Here a missing mysqldump or gzip is a normal
     * case handled by a fallback, so detect it quietly, using the same
     * primitives as the Cli "auto" strategy (proc_open, then exec) so the
     * command is still found on servers where only shell_exec is disabled.
     */
    protected function findCommandPath(string $command): ?string
    {
        $cmd = sprintf('command -v %s 2>/dev/null', escapeshellarg($command));
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $available = function ($name) use ($disabled) {
            return function_exists($name) && !in_array($name, $disabled, true);
        };

        $output = '';
        if ($available('proc_open')) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open($cmd, $descriptors, $pipes, getcwd());
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $output = (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
        } elseif ($available('exec')) {
            $lines = [];
            @exec($cmd, $lines);
            $output = implode("\n", $lines);
        } elseif ($available('shell_exec')) {
            $output = (string) @shell_exec($cmd);
        }

        $output = trim($output);
        return $output === '' ? null : $output;
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

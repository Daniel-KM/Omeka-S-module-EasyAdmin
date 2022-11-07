<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbLog extends AbstractCheck
{
    /**
     * @var string
     */
    protected $table = 'log';

    /**
     * @var array
     */
    protected $severities = [
        '0' => 'Emergency', // @translate
        '1' => 'Alert', // @translate
        '2' => 'Critical', // @translate
        '3' => 'Error', // @translate
        '4' => 'Warning', // @translate
        '5' => 'Notice', // @translate
        '6' => 'Info', // @translate
        '7' => 'Debug', // @translate
    ];

    public function perform(): void
    {
        parent::perform();

        if (!$this->checkTableExists('log')) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'There is no table "{table}" to check.', // @translate
                ['table' => $this->table]
            );
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_log_clean';

        $days = (string) $this->getArg('days');
        $severity = (string) $this->getArg('severity');
        if ($processFix && !(is_numeric($days) || !is_numeric($severity))) {
            $this->logger->warn(
                'A minimum number of days and a maximal severity is needed to clean logs.' // @translate
            );
            return;
        }

        $days = (int) $days;
        $severity = (int) $severity;
        if (!isset($this->severities[$severity])) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The severity "{severity}" is not managed.', // @translate
                ['severity' => $severity]
            );
            return;
        }

        $this->checkDbLog($processFix, $days, $severity);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function checkTableExists(string $table): bool
    {
        $dbname = $this->connection->getDatabase();
        $table = $this->connection->quote($table);
        $sql = <<<SQL
SELECT *
FROM information_schema.TABLES
WHERE table_schema = "$dbname"
    AND table_name = $table
LIMIT 1;
SQL;
        return (bool) $this->connection->executeQuery($sql)->fetchOne();
    }

    /**
     * Check the size of the db table "log".
     *
     * @param bool $fix
     * @param int $minimumDays
     * @param int $maximumSeverity
     */
    protected function checkDbLog(bool $fix = false, int $minimumDays = 0, int $maximumSeverity = 0): void
    {
        $timestamp = time() - 86400 * $minimumDays;
        $date = date('Y-m-d H:i:s', $timestamp);

        $dbname = $this->connection->getDatabase();
        $sqlSize = <<<SQL
SELECT ROUND((data_length + index_length) / 1024 / 1024, 2)
FROM information_schema.TABLES
WHERE table_schema = "$dbname"
    AND table_name = "$this->table";
SQL;
        $size = $this->connection->executeQuery($sqlSize)->fetchOne();

        $sql = "SELECT COUNT(id) FROM $this->table;";
        $all = $this->connection->executeQuery($sql)->fetchOne();

        $sql = "SELECT COUNT(id) FROM $this->table WHERE created < :date AND severity >= :severity;";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':date', $date);
        $stmt->bindValue(':severity', $maximumSeverity);
        $old = $stmt->executeQuery()->fetchOne();

        $this->logger->notice(
            'The table "{table}" has a size of {size} MB. {old}/{all} records are older than {days} days and below or equal severity "{severity}".', // @translate
            ['table' => $this->table,'size' => $size, 'old' => $old, 'all' => $all, 'days' => $minimumDays, 'severity' => $this->severities[$maximumSeverity]]
        );

        if ($fix) {
            $sql = "DELETE FROM `$this->table` WHERE created < :date AND severity >= :severity;";
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue(':date', $date);
            $stmt->bindValue(':severity', $maximumSeverity);
            $stmt->executeStatement();
            $count = $stmt->rowCount();
            $size = $this->connection->executeQuery($sqlSize)->fetchOne();
            $this->logger->notice(
                '{count} records older than {days} days with maximum severity "{severity}" were removed. The table "{table}" has a size of {size} MB.', // @translate
                ['count' => $count, 'days' => $minimumDays, 'size' => $size, 'severity' => $this->severities[$maximumSeverity], 'table' => $this->table]
            );
        }
    }
}

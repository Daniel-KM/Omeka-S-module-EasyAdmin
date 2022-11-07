<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbSession extends AbstractCheck
{
    /**
     * @var string
     */
    protected $table = 'session';

    public function perform(): void
    {
        parent::perform();

        $process = $this->getArg('process');
        $processFix = $process === 'db_session_clean';

        $minimumDays = (string) $this->getArg('days');
        if ($processFix && !is_numeric($minimumDays)) {
            $this->logger->warn(
                'A minimum number of days is needed to clean sessions.' // @translate
            );
            return;
        }

        $this->checkDbSession($processFix, (int) $minimumDays);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    /**
     * Check the size of the db table "session".
     *
     * @param bool $fix
     * @param int $minimumDays
     */
    protected function checkDbSession(bool $fix = false, int $minimumDays = 0): void
    {
        $timestamp = time() - 86400 * $minimumDays;

        $dbname = $this->connection->getDatabase();
        $sqlSize = <<<SQL
SELECT ROUND((data_length + index_length) / 1024 / 1024, 2)
FROM information_schema.TABLES
WHERE table_schema = "$dbname"
    AND table_name = "$this->table";
SQL;
        $size = $this->connection->executeQuery($sqlSize)->fetchOne();

        $sql = "SELECT COUNT(id) FROM $this->table WHERE modified < :timestamp;";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':timestamp', $timestamp);
        $old = $stmt->executeQuery()->fetchOne();

        $sql = "SELECT COUNT(id) FROM $this->table;";
        $all = $this->connection->executeQuery($sql)->fetchOne();
        $this->logger->notice(
            'The table "{table}" has a size of {size} MB. {old}/{all} records are older than {days} days.', // @translate
            ['table' => $this->table,'size' => $size, 'old' => $old, 'all' => $all, 'days' => $minimumDays]
        );

        if ($fix) {
            $sql = "DELETE FROM `$this->table` WHERE modified < :timestamp;";
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue(':timestamp', $timestamp);
            $stmt->executeStatement();
            $count = $stmt->rowCount();
            $size = $this->connection->executeQuery($sqlSize)->fetchOne();
            $this->logger->notice(
                '{count} records older than {days} days were removed. The table "{table}" has a size of {size} MB.', // @translate
                ['count' => $count, 'days' => $minimumDays, 'size' => $size, 'table' => $this->table]
            );
        }
    }
}

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
        $processRecreate = $process === 'db_session_recreate';

        $minimumDays = (string) $this->getArg('days');
        if ($processFix && !is_numeric($minimumDays)) {
            $this->logger->warn(
                'A minimum number of days is needed to clean sessions.' // @translate
            );
            return;
        }

        $days = (int) $minimumDays;

        $quick = !empty($this->getArg('quick'));
        if ($quick) {
            $this->deleteLastSession($days);
            return;
        }

        $this->checkDbSession($processFix, (int) $minimumDays, $processRecreate);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function deleteLastSession(int $days): void
    {
        // No message, except error.
        $table = 'session';
        $column = 'modified';
        $result = $this->connectionDbal->executeQuery("SHOW INDEX FROM `$table` WHERE `column_name` = '$column';");
        if (!$result->fetchOne()) {
            try {
                $this->connectionDbal->executeStatement("ALTER TABLE `$table` ADD INDEX `$column` (`$column`);");
            } catch (\Exception $e) {
                $this->logger->warn(
                    'Unable to add index "{column}" in table "{table}" to improve performance: {msg}', // @translate
                    ['column' => $column, 'table' => $table, 'msg' => $e->getMessage()]
                );
            }
        }

        $time = time();
        $sql = 'DELETE `session` FROM `session` WHERE `modified` < :time;';

        try {
            $this->connectionDbal->executeStatement(
                $sql,
                ['time' => $time - $days * 86400],
                ['time' => \Doctrine\DBAL\ParameterType::INTEGER]
            );
        } catch (\Exception $e) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Unable to delete last sessions: {msg}', // @translate
                ['msg' => $e->getMessage()]
            );
        }
    }

    /**
     * Check the size of the db table "session".
     *
     * @param bool $fix
     * @param int $days
     */
    protected function checkDbSession(bool $fix = false, int $days = 0, bool $recreate = false): void
    {
        $timestamp = time() - 86400 * $days;

        $dbname = $this->connection->getDatabase();
        $sqlSize = <<<SQL
            SELECT ROUND((data_length + index_length) / 1024 / 1024, 2)
            FROM information_schema.TABLES
            WHERE table_schema = "$dbname"
                AND table_name = "$this->table";
            SQL;
        $size = $this->connection->executeQuery($sqlSize)->fetchOne();

        if ($recreate) {
            $sql = <<<SQL
                SET foreign_key_checks = 0;
                CREATE TABLE `session_new` LIKE `session`;
                RENAME TABLE `session` TO `session_old`, `session_new` TO `session`;
                DROP TABLE `session_old`;
                SET foreign_key_checks = 1;
                SQL;
            $this->connection->executeStatement($sql);
            return;
        }

        $sql = "SELECT COUNT(id) FROM $this->table WHERE modified < :timestamp;";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':timestamp', $timestamp);
        $old = $stmt->executeQuery()->fetchOne();

        $sql = "SELECT COUNT(id) FROM $this->table;";
        $all = $this->connection->executeQuery($sql)->fetchOne();
        $this->logger->notice(
            'The table "{table}" has a size of {size} MB. {old}/{all} records are older than {days} days.', // @translate
            ['table' => $this->table,'size' => $size, 'old' => $old, 'all' => $all, 'days' => $days]
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
                ['count' => $count, 'days' => $days, 'size' => $size, 'table' => $this->table]
            );
        }
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbSession extends AbstractCheck
{
    /**
     * @var string
     */
    protected $table = 'session';

    /**
     * Number of seconds for a session-age param value (clean value, without the
     * legacy "session_" prefix). Shared by every cron executor.
     */
    public static function secondsForAge(string $age): ?int
    {
        $map = [
            '1h' => 3600,
            '2h' => 7200,
            '4h' => 14400,
            '12h' => 43200,
            '1d' => 86400,
            '2d' => 172800,
            '8d' => 691200,
            '30d' => 2592000,
        ];
        // Tolerate a still-prefixed legacy value ("session_8d").
        $age = strncmp($age, 'session_', 8) === 0 ? substr($age, 8) : $age;
        return $map[$age] ?? null;
    }

    public function perform(): void
    {
        parent::perform();

        // The session id allows to impersonate the user, so it is removed from
        // the args stored in the database as soon as it is read.
        $sessionId = (string) $this->getArg('session_id');
        if ($sessionId !== '') {
            $args = $this->job->getArgs();
            unset($args['session_id']);
            $this->job->setArgs($args);
            $this->entityManager->flush();
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_session_clean';
        $processRecreate = $process === 'db_session_recreate';

        // The form sends "days", the quick mode sends "seconds" directly.
        $quick = !empty($this->getArg('quick'));
        if ($quick) {
            $seconds = (int) $this->getArg('seconds');
            $this->deleteLastSession($seconds);
            return;
        }

        $days = (string) $this->getArg('days');
        if ($processFix && !is_numeric($days)) {
            $this->logger->warn(
                'A minimum number of days is needed to clean sessions.' // @translate
            );
            return;
        }

        $seconds = (int) $days * 86400;

        $this->checkDbSession($processFix, $seconds, $processRecreate, $sessionId);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function deleteLastSession(int $seconds): void
    {
        // No message, except error.
        $this->addIndexModified('session');

        $time = time();
        $sql = 'DELETE `session` FROM `session` WHERE `modified` < :time;';

        try {
            $this->connectionDbal->executeStatement(
                $sql,
                ['time' => $time - $seconds],
                ['time' => \Doctrine\DBAL\ParameterType::INTEGER]
            );
        } catch (\Throwable $e) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Unable to delete last sessions: {msg}', // @translate
                ['msg' => $e->getMessage()]
            );
        }
    }

    /**
     * Check the size of the db table "session".
     */
    protected function checkDbSession(bool $fix = false, int $seconds = 0, bool $recreate = false, string $sessionId = ''): void
    {
        $timestamp = time() - $seconds;

        $dbname = $this->connection->getDatabase();
        $sqlSize = <<<'SQL'
            SELECT ROUND((data_length + index_length) / 1024 / 1024, 2)
            FROM information_schema.TABLES
            WHERE table_schema = ?
                AND table_name = ?
            SQL;
        $size = $this->connection->executeQuery($sqlSize, [$dbname, $this->table])->fetchOne();

        if ($recreate) {
            $this->recreateTable($sessionId, (float) $size);
            return;
        }

        // The old sessions are selected and deleted by the column "modified".
        $this->addIndexModified($this->table);

        $sql = "SELECT COUNT(id) FROM $this->table WHERE modified < :timestamp;";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':timestamp', $timestamp);
        $old = $stmt->executeQuery()->fetchOne();

        $sql = "SELECT COUNT(id) FROM $this->table;";
        $all = $this->connection->executeQuery($sql)->fetchOne();
        $this->logger->notice(
            'The table "{table}" has a size of {size} MB. {old}/{all} records are older than {total} seconds.', // @translate
            ['table' => $this->table,'size' => $size, 'old' => $old, 'all' => $all, 'total' => $seconds]
        );

        if ($fix) {
            $sql = "DELETE FROM `$this->table` WHERE modified < :timestamp;";
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue(':timestamp', $timestamp);
            $count = $stmt->executeStatement();
            $size = $this->connection->executeQuery($sqlSize, [$dbname, $this->table])->fetchOne();
            $this->logger->notice(
                '{count} records older than {seconds} seconds were removed. The table "{table}" has a size of {size} MB.', // @translate
                ['count' => $count, 'seconds' => $seconds, 'size' => $size, 'table' => $this->table]
            );
        }
    }

    /**
     * Replace the table of sessions by an empty copy, except the current one.
     *
     * The copy is swapped in a single atomic rename, so the requests are not
     * blocked, whatever the size of the table. The session of the user who
     * launched the task is copied, so the user remains logged in. The index on
     * the column "modified" is added to the empty table, where it is instant.
     *
     * @see \LazySession\Module::emptySessionTable()
     */
    protected function recreateTable(string $sessionId, float $size): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS `session_new`');
        $this->connection->executeStatement('CREATE TABLE `session_new` LIKE `session`');
        $this->addIndexModified('session_new');
        if ($sessionId !== '') {
            $this->connection->executeStatement(
                'INSERT INTO `session_new` SELECT * FROM `session` WHERE `id` = :id',
                ['id' => $sessionId]
            );
        }
        // A previous table may remain when a previous process failed.
        $this->connection->executeStatement('DROP TABLE IF EXISTS `session_old`');
        $this->connection->executeStatement('RENAME TABLE `session` TO `session_old`, `session_new` TO `session`');
        $this->connection->executeStatement('DROP TABLE `session_old`');
        $this->logger->notice(
            'The table "{table}" of {size} MB was recreated empty, except the session of the current user.', // @translate
            ['table' => $this->table, 'size' => $size]
        );
    }

    /**
     * Add the index on the column "modified" when missing.
     *
     * The garbage collector of the sessions deletes the old ones by this column,
     * so without index, it scans and locks the whole table.
     */
    protected function addIndexModified(string $table): void
    {
        $result = $this->connection->executeQuery("SHOW INDEX FROM `$table` WHERE `Column_name` = 'modified'");
        if ($result->fetchOne()) {
            return;
        }
        try {
            $this->connection->executeStatement("ALTER TABLE `$table` ADD INDEX `modified` (`modified`)");
        } catch (\Throwable $e) {
            $this->logger->warn(
                'Unable to add index "{column}" in table "{table}" to improve performance: {msg}', // @translate
                ['column' => 'modified', 'table' => $table, 'msg' => $e->getMessage()]
            );
        }
    }
}

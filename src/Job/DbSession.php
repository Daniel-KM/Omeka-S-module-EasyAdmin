<?php
namespace BulkCheck\Job;

class DbSession extends AbstractCheck
{
    /**
     * @var int
     */
    const SESSION_OLD_DAYS = 100;

    public function perform()
    {
        parent::perform();

        $process = $this->getArg('process');

        $this->checkDbSession($process === 'db_session_clean');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    /**
     * Check the size of the db table session.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkDbSession($fix = false)
    {
        $timestamp = time() - 86400 * self::SESSION_OLD_DAYS;

        $dbname = $this->connection->getDatabase();
        $sqlSize = <<<SQL
SELECT ROUND((data_length + index_length) / 1024 / 1024, 2)
FROM information_schema.TABLES
WHERE table_schema = "$dbname"
    AND table_name = "session";
SQL;
        $size = $this->connection->query($sqlSize)->fetchColumn();
        $sql = 'SELECT COUNT(id) FROM session WHERE modified < :timestamp;';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':timestamp', $timestamp);
        $stmt->execute();
        $old = $stmt->fetchColumn();
        $sql = 'SELECT COUNT(id) FROM session;';
        $all = $this->connection->query($sql)->fetchColumn();
        $this->logger->notice(
            'The table "session" has a size of {size} MB. {old}/{all} records are older than 100 days.', // @translate
            ['size' => $size, 'old' => $old, 'all' => $all]
        );

        if ($fix) {
            $sql = 'DELETE FROM `session` WHERE modified < :timestamp;';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue(':timestamp', $timestamp);
            $stmt->execute();
            $count = $stmt->rowCount();
            $size = $this->connection->query($sqlSize)->fetchColumn();
            $this->logger->notice(
                '{count} records older than {days} days were removed. The table "session" has a size of {size} MB.', // @translate
                ['count' => $count, 'days' => self::SESSION_OLD_DAYS, 'size' => $size]
            );
        }
    }
}

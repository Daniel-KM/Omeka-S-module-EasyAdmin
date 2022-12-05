<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbContentLock extends AbstractCheck
{
    /**
     * @var string
     */
    protected $table = 'content_lock';

    public function perform(): void
    {
        parent::perform();

        $process = $this->getArg('process');
        $processFix = $process === 'db_content_lock_clean';

        $minimumHours = (string) $this->getArg('hours');
        if (strlen($minimumHours) && !is_numeric($minimumHours)) {
            $this->logger->warn(
                'The number of hours should be numeric.' // @translate
            );
            return;
        }

        $userIds = array_unique(array_map('intval', $this->getArg('user_id') ?: []));

        $this->checkDbContentLock($processFix, (int) $minimumHours, $userIds);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    /**
     * Check and fix the content locks.
     *
     * @param bool $fix
     * @param int $minimumHours
     * @param int[] $userIds
     */
    protected function checkDbContentLock(bool $fix = false, int $minimumHours = 0, array $userIds = []): void
    {
        $timestamp = time() - 3600 * $minimumHours;
        $date = date('Y-m-d H:i:s', $timestamp);

        $sql = "SELECT COUNT(id) FROM $this->table;";
        $all = $this->connection->executeQuery($sql)->fetchOne();

        $sql = "SELECT COUNT(id) FROM $this->table WHERE 1 = 1";
        $bind = [];
        $types = [];
        if ($date) {
            $sql .= ' AND created < :date';
            $bind['date'] = $date;
            $types['date'] = \Doctrine\DBAL\ParameterType::STRING;
        }
        if ($userIds) {
            $sql .= ' AND user_id IN (:user_ids)';
            $bind['user_ids'] = $userIds;
            $types['user_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        }
        $old = $this->connection->executeQuery($sql, $bind, $types)->fetchOne();

        if ($userIds) {
            $this->logger->notice(
                'There are {old}/{all} content locks older than {hours} hours for {total} users.', // @translate
                ['old' => $old, 'all' => $all, 'hours' => $minimumHours, 'total' => count($userIds)]
            );
        } else {
            $this->logger->notice(
                'There are {old}/{all} content locks older than {hours} hours.', // @translate
                ['old' => $old, 'all' => $all, 'hours' => $minimumHours]
            );
        }

        if ($fix) {
            $sql = str_replace ('SELECT COUNT(id) FROM', 'DELETE FROM', $sql);
            $count = $this->connection->executeStatement($sql, $bind, $types);
            if ($userIds) {
                $this->logger->notice(
                    '{count} content locks of {total} users older than {hours} hours were removed.', // @translate
                    ['count' => $count, 'total' => count($userIds), 'hours' => $minimumHours]
                );
            } else {
                $this->logger->notice(
                    '{count} content locks older than {hours} hours were removed.', // @translate
                    ['count' => $count, 'hours' => $minimumHours]
                );
            }
        }
    }
}

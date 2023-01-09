<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbJob extends AbstractCheck
{
    public function perform(): void
    {
        parent::perform();

        $process = $this->getArg('process');
        switch ($process) {
            case 'db_job_fix_all':
                $this->checkDbJob(true, true);
                break;
            case 'db_job_check':
            case 'db_job_fix':
            default:
                $this->checkDbJob($process === 'db_job_fix');
                break;
        }

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    /**
     * Check the never ending jobs.
     *
     * @param bool $fix
     * @param bool $fixAll
     */
    protected function checkDbJob($fix = false, $fixAll = false)
    {
        $sql = <<<SQL
SELECT id, pid, status
FROM job
WHERE id != :jobid
    AND status IN ("starting", "stopping", "in_progress")
ORDER BY id ASC;
SQL;

        // Fetch all: jobs are few, except if admin never checks result of jobs.
        $result = $this->connection->executeQuery($sql, ['jobid' => $this->job->getId()])->fetchAllAssociative();

        // Unselect processes with an existing pid.
        foreach ($result as $id => $row) {
            // TODO The check of the pid works only with Linux.
            if ($row['pid'] && file_exists('/proc/' . $row['pid'])) {
                unset($result[$id]);
            }
        }

        if ($fixAll) {
            $sql = 'SELECT COUNT(id) FROM job';
            $countJobs = $this->connection->executeQuery($sql)->fetchOne();

            $sql = <<<SQL
UPDATE job
SET status = "stopped"
WHERE id != :jobid
    AND status IN ("starting", "stopping");
SQL;
            $stopped = $this->connection->executeQuery($sql, ['jobid' => $this->job->getId()])->rowCount();

            $sql = <<<SQL
UPDATE job
SET status = "error"
WHERE id != :jobid
    AND status IN ("in_progress");
SQL;
            $error = $this->connection->executeQuery($sql, ['jobid' => $this->job->getId()])->rowCount();

            $this->logger->notice(
                'Dead jobs were cleaned: {count_stopped} marked "stopped" and {count_error} marked "error" on a total of {count_jobs}.', // @translate
                [
                    'count_stopped' => $stopped,
                    'count_error' => $error,
                    'count_jobs' => $countJobs,
                ]
            );
            return;
        }

        if (empty($result)) {
            $this->logger->notice(
                'There is no dead job.' // @translate
            );
            return;
        }

        $this->logger->notice(
            'The following {count} jobs are dead: {job_ids}.', // @translate
            [
                'count' => count($result),
                'job_ids' => implode(', ', array_map(function ($v) {
                    return '#' . $v['id'];
                }, $result)),
            ]
        );

        if ($fix) {
            $stopped = [];
            $errored = [];
            foreach ($result as $value) {
                if ($value['status'] === 'in_progress') {
                    $errored[] = (int) $value['id'];
                } else {
                    $stopped[] = (int) $value['id'];
                }
            }

            if ($stopped) {
                $sql = 'UPDATE job SET status = "stopped" WHERE id IN (' . implode(',', $stopped) . ')';
                $this->connection->executeStatement($sql);
            }

            if ($errored) {
                $sql = 'UPDATE job SET status = "error" WHERE id IN (' . implode(',', $errored) . ')';
                $this->connection->executeStatement($sql);
            }

            $this->logger->notice(
                'A total of {count} dead jobs have been cleaned.', // @translate
                ['count' => count($result)]
            );
        }
    }
}

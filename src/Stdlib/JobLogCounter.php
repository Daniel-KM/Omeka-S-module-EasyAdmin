<?php declare(strict_types=1);

namespace EasyAdmin\Stdlib;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Laminas\View\Helper\Url;

/**
 * Count the errors and the warnings logged by jobs.
 *
 * Each line of the log of a job contains its priority, like "ERR (3):".
 */
class JobLogCounter
{
    const ERRORS = 'errors';
    const WARNINGS = 'warnings';

    protected const MARKERS = [
        self::ERRORS => ['EMERG (0): ', 'ALERT (1): ', 'CRIT (2): ', 'ERR (3): '],
        self::WARNINGS => ['WARN (4): '],
    ];

    /**
     * @var Connection
     */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param int[] $jobIds
     * @return array Counts of errors and warnings by job id, without the jobs
     *   without errors nor warnings.
     */
    public function count(array $jobIds): array
    {
        $jobIds = $this->cleanIds($jobIds);
        if (!$jobIds) {
            return [];
        }

        $selects = [];
        foreach (self::MARKERS as $level => $markers) {
            $parts = [];
            foreach ($markers as $marker) {
                $quoted = $this->connection->quote($marker);
                $parts[] = "(CHAR_LENGTH(`log`) - CHAR_LENGTH(REPLACE(`log`, $quoted, ''))) / CHAR_LENGTH($quoted)";
            }
            $selects[] = 'COALESCE(' . implode(' + ', $parts) . ', 0) AS `' . $level . '`';
        }
        $rows = $this->connection->executeQuery(
            'SELECT `id`, ' . implode(', ', $selects) . ' FROM `job` WHERE `id` IN (:ids)',
            ['ids' => $jobIds],
            ['ids' => Connection::PARAM_INT_ARRAY]
        )->fetchAllAssociative();

        return $this->filterCounts(array_column($rows, null, 'id'));
    }

    /**
     * Limit a search of jobs to the ones with logged errors or warnings.
     */
    public function addFilter(QueryBuilder $qb, string $alias, string $level): void
    {
        if (!isset(self::MARKERS[$level])) {
            return;
        }
        $expr = $qb->expr();
        $conditions = [];
        foreach (self::MARKERS[$level] as $index => $marker) {
            $param = 'easyadmin_job_log_' . $level . '_' . $index;
            $qb->setParameter($param, '%' . $marker . '%');
            $conditions[] = $expr->like($alias . '.log', ':' . $param);
        }
        $qb->andWhere($expr->orX(...$conditions));
    }

    /**
     * Get the url of the logs of a job.
     */
    public function url(Url $url, int $jobId): string
    {
        return $url('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $jobId]);
    }

    protected function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Keep the jobs with errors or warnings and cast the counts.
     */
    protected function filterCounts(array $rows): array
    {
        $result = [];
        foreach ($rows as $id => $row) {
            $counts = [
                self::ERRORS => (int) $row[self::ERRORS],
                self::WARNINGS => (int) $row[self::WARNINGS],
            ];
            if ($counts[self::ERRORS] || $counts[self::WARNINGS]) {
                $result[(int) $id] = $counts;
            }
        }
        return $result;
    }
}

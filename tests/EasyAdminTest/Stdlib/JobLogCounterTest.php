<?php declare(strict_types=1);

namespace EasyAdminTest\Stdlib;

use EasyAdmin\Stdlib\JobLogCounter;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Count the errors and warnings written in the log of the jobs.
 *
 * @group stdlib
 */
class JobLogCounterTest extends AbstractHttpControllerTestCase
{
    /**
     * @var int[]
     */
    protected $jobIds = [];

    public function tearDown(): void
    {
        if ($this->jobIds) {
            $this->connection()->executeStatement('DELETE FROM `job` WHERE `id` IN (' . implode(',', $this->jobIds) . ')');
        }
        parent::tearDown();
    }

    public function testCountsByLevelWithoutCleanJobs(): void
    {
        $failed = $this->insertJob("2026 INFO (6): Start\n2026 ERR (3): First\n2026 CRIT (2): Second\n2026 WARN (4): Third\n");
        $warned = $this->insertJob("2026 WARN (4): Only a warning\n");
        $clean = $this->insertJob("2026 NOTICE (5): Nothing wrong\n");
        $empty = $this->insertJob(null);

        $counts = $this->counter()->count([$failed, $warned, $clean, $empty, 0]);

        $this->assertSame([
            $failed => [JobLogCounter::ERRORS => 2, JobLogCounter::WARNINGS => 1],
            $warned => [JobLogCounter::ERRORS => 0, JobLogCounter::WARNINGS => 1],
        ], $counts);
    }

    public function testFilterOfTheSearchOfJobs(): void
    {
        $failed = $this->insertJob("2026 ERR (3): Failed\n");
        $warned = $this->insertJob("2026 WARN (4): Warned\n");
        $this->insertJob("2026 INFO (6): Clean\n");

        $this->assertSame([$failed], $this->searchIds(JobLogCounter::ERRORS));
        $this->assertSame([$warned], $this->searchIds(JobLogCounter::WARNINGS));
    }

    protected function counter(): JobLogCounter
    {
        return new JobLogCounter($this->connection());
    }

    protected function connection(): \Doctrine\DBAL\Connection
    {
        return $this->getApplication()->getServiceManager()->get('Omeka\Connection');
    }

    protected function insertJob(?string $log): int
    {
        $this->connection()->executeStatement(
            'INSERT INTO `job` (`status`, `class`, `log`, `started`) VALUES (?, ?, ?, NOW())',
            ['completed', 'EasyAdminTest\Job', $log]
        );
        $id = (int) $this->connection()->lastInsertId();
        $this->jobIds[] = $id;
        return $id;
    }

    protected function searchIds(string $level): array
    {
        $entityManager = $this->getApplication()->getServiceManager()->get('Omeka\EntityManager');
        $qb = $entityManager->createQueryBuilder()
            ->select('omeka_root.id')
            ->from(\Omeka\Entity\Job::class, 'omeka_root')
            ->where('omeka_root.id IN (:ids)')
            ->setParameter('ids', $this->jobIds)
            ->orderBy('omeka_root.id');
        $this->counter()->addFilter($qb, 'omeka_root', $level);
        return array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
    }
}

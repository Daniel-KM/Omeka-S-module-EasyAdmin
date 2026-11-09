<?php declare(strict_types=1);

namespace EasyAdminTest\Job;

use EasyAdmin\Job\ManageAddons;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin ManageAddons Job.
 */
class ManageAddonsTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test job class exists.
     */
    public function testJobClassExists(): void
    {
        $this->assertTrue(class_exists(ManageAddons::class));
    }

    /**
     * Test job extends AbstractJob.
     */
    public function testJobExtendsAbstractJob(): void
    {
        $reflection = new \ReflectionClass(ManageAddons::class);
        $this->assertTrue($reflection->isSubclassOf(\Omeka\Job\AbstractJob::class));
    }

    /**
     * Test job has perform method.
     */
    public function testJobHasPerformMethod(): void
    {
        $this->assertTrue(method_exists(ManageAddons::class, 'perform'));
    }

    /**
     * Test the finalization of modules that do not need it is logged, so a
     * process that does nothing is not silent.
     */
    public function testFinalizeLogsStartAndSkippedModules(): void
    {
        $services = $this->getApplicationServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        $writer = new \Laminas\Log\Writer\Mock();
        $services->get('Omeka\Logger')->addWriter($writer);

        $job = new \Omeka\Entity\Job();
        $job->setClass(ManageAddons::class);
        $job->setArgs([
            'operation' => 'finalize',
            'type' => 'module',
            'addons' => ['Common', 'EasyAdmin'],
        ]);
        $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
        $entityManager->persist($job);
        $entityManager->flush();

        try {
            (new ManageAddons($job, $services))->perform();
        } finally {
            $entityManager->remove($job);
            $entityManager->flush();
        }

        $messages = array_map(fn ($event) => (string) $event['message'], $writer->events);
        $this->assertNotEmpty(preg_grep('~^Starting the process "\{operation\}"~', $messages));
        $this->assertNotEmpty(preg_grep('~^No upgrade needed for: \{addons\}~', $messages));
        $this->assertSame(\Omeka\Entity\Job::STATUS_IN_PROGRESS, $job->getStatus());
    }
}

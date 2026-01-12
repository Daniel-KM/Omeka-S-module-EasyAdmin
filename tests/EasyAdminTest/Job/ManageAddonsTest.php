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
}

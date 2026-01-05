<?php declare(strict_types=1);

namespace EasyAdminTest\Job;

use EasyAdmin\Job\DbSession;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin DbSession Job.
 */
class DbSessionTest extends AbstractHttpControllerTestCase
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
        $this->assertTrue(class_exists(DbSession::class));
    }

    /**
     * Test job extends AbstractCheck.
     */
    public function testJobExtendsAbstractCheck(): void
    {
        $reflection = new \ReflectionClass(DbSession::class);
        $this->assertTrue($reflection->isSubclassOf(\EasyAdmin\Job\AbstractCheck::class));
    }

    /**
     * Test job has perform method.
     */
    public function testJobHasPerformMethod(): void
    {
        $this->assertTrue(method_exists(DbSession::class, 'perform'));
    }
}

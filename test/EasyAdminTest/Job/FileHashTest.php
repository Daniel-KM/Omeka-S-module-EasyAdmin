<?php declare(strict_types=1);

namespace EasyAdminTest\Job;

use EasyAdmin\Job\FileHash;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin FileHash Job.
 */
class FileHashTest extends AbstractHttpControllerTestCase
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
        $this->assertTrue(class_exists(FileHash::class));
    }

    /**
     * Test job extends AbstractCheckFile.
     */
    public function testJobExtendsAbstractCheckFile(): void
    {
        $reflection = new \ReflectionClass(FileHash::class);
        $this->assertTrue($reflection->isSubclassOf(\EasyAdmin\Job\AbstractCheckFile::class));
    }

    /**
     * Test job has perform method.
     */
    public function testJobHasPerformMethod(): void
    {
        $this->assertTrue(method_exists(FileHash::class, 'perform'));
    }
}

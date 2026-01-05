<?php declare(strict_types=1);

namespace EasyAdminTest\Job;

use EasyAdmin\Job\DatabaseBackup;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin DatabaseBackup Job.
 */
class DatabaseBackupTest extends AbstractHttpControllerTestCase
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
        $this->assertTrue(class_exists(DatabaseBackup::class));
    }

    /**
     * Test job extends AbstractJob.
     */
    public function testJobExtendsAbstractJob(): void
    {
        $reflection = new \ReflectionClass(DatabaseBackup::class);
        $this->assertTrue($reflection->isSubclassOf(\Omeka\Job\AbstractJob::class));
    }

    /**
     * Test job has perform method.
     */
    public function testJobHasPerformMethod(): void
    {
        $this->assertTrue(method_exists(DatabaseBackup::class, 'perform'));
    }

    /**
     * Test job can be dispatched.
     */
    public function testJobCanBeDispatched(): void
    {
        $dispatcher = $this->getService('Omeka\Job\Dispatcher');
        $this->assertNotNull($dispatcher);
    }

    /**
     * Test normalizeDefiner replaces quoted DEFINER with CURRENT_USER.
     */
    public function testNormalizeDefinerQuoted(): void
    {
        $input = "CREATE DEFINER=`omeka_user`@`localhost` VIEW `test_view` AS SELECT 1";
        $expected = "CREATE DEFINER=CURRENT_USER VIEW `test_view` AS SELECT 1";

        $result = $this->invokeNormalizeDefiner($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test normalizeDefiner replaces DEFINER with wildcard host.
     */
    public function testNormalizeDefinerWildcardHost(): void
    {
        $input = "CREATE DEFINER=`admin`@`%` PROCEDURE `test_proc`() BEGIN END";
        $expected = "CREATE DEFINER=CURRENT_USER PROCEDURE `test_proc`() BEGIN END";

        $result = $this->invokeNormalizeDefiner($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test normalizeDefiner handles MySQL conditional comments.
     */
    public function testNormalizeDefinerConditionalComment(): void
    {
        $input = "/*!50013 DEFINER=`root`@`127.0.0.1` SQL SECURITY DEFINER */";
        $expected = "/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */";

        $result = $this->invokeNormalizeDefiner($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test normalizeDefiner handles unquoted format.
     */
    public function testNormalizeDefinerUnquoted(): void
    {
        $input = "CREATE DEFINER=root@localhost FUNCTION test() RETURNS INT BEGIN RETURN 1; END";
        $expected = "CREATE DEFINER=CURRENT_USER FUNCTION test() RETURNS INT BEGIN RETURN 1; END";

        $result = $this->invokeNormalizeDefiner($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test normalizeDefiner preserves SQL without DEFINER.
     */
    public function testNormalizeDefinerNoDefiner(): void
    {
        $input = "CREATE VIEW `test_view` AS SELECT * FROM `items`";

        $result = $this->invokeNormalizeDefiner($input);
        $this->assertEquals($input, $result);
    }

    /**
     * Test normalizeDefiner is case insensitive.
     */
    public function testNormalizeDefinerCaseInsensitive(): void
    {
        $input = "CREATE definer=`User`@`Host` VIEW test AS SELECT 1";
        $expected = "CREATE DEFINER=CURRENT_USER VIEW test AS SELECT 1";

        $result = $this->invokeNormalizeDefiner($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Invoke the protected normalizeDefiner method via reflection.
     */
    protected function invokeNormalizeDefiner(string $sql): string
    {
        $reflection = new \ReflectionClass(DatabaseBackup::class);
        $method = $reflection->getMethod('normalizeDefiner');
        $method->setAccessible(true);

        // Create a mock instance (we don't need full initialization).
        $job = $reflection->newInstanceWithoutConstructor();

        return $method->invoke($job, $sql);
    }
}

<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Cron Controller.
 */
class CronControllerTest extends AbstractHttpControllerTestCase
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
     * Test cron index page is accessible.
     */
    public function testCronIndexAction(): void
    {
        $this->dispatch('/admin/easy-admin/cron');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('EasyAdmin\Controller\Admin\Cron');
        $this->assertActionName('index');
    }

    /**
     * The command suggested for the cron uses the path of the script inside
     * the directory of Omeka, not its resolved path: the directory of the
     * module may be a symbolic link, and the task finds the root of Omeka from
     * the path used to call it.
     */
    public function testCronCommandUsesPathInsideOmeka(): void
    {
        // The module Cron replaces this page with its own one.
        if (class_exists('Cron\\Module', false)) {
            $this->markTestSkipped('The page is managed by the module Cron.');
        }

        $this->dispatch('/admin/easy-admin/cron');

        $this->assertResponseStatusCode(200);
        $expected = is_file(OMEKA_PATH . '/modules/EasyAdmin/data/scripts/task.php')
            ? OMEKA_PATH . '/modules/EasyAdmin/data/scripts/task.php'
            : OMEKA_PATH . '/composer-addons/modules/EasyAdmin/data/scripts/task.php';
        $this->assertStringContainsString(
            'php ' . $expected . ' ',
            html_entity_decode($this->getResponse()->getContent(), ENT_QUOTES)
        );
    }

    /**
     * Test cron page requires authentication.
     */
    public function testCronRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/cron');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test cron controller is registered.
     */
    public function testCronControllerIsRegistered(): void
    {
        $controllerManager = $this->getService('ControllerManager');

        $this->assertTrue(
            $controllerManager->has('EasyAdmin\Controller\Admin\Cron')
        );
    }
}

<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Maintenance Controller.
 */
class MaintenanceControllerTest extends AbstractHttpControllerTestCase
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
     * Test maintenance controller is registered.
     */
    public function testMaintenanceControllerIsRegistered(): void
    {
        $controllerManager = $this->getService('ControllerManager');

        $this->assertTrue(
            $controllerManager->has('Omeka\Controller\Admin\Maintenance')
        );
    }

    /**
     * Test EasyAdmin main page requires authentication.
     */
    public function testEasyAdminMainPageRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test maintenance controller class exists.
     */
    public function testMaintenanceControllerClassExists(): void
    {
        $this->assertTrue(class_exists(\EasyAdmin\Controller\Admin\MaintenanceController::class));
    }
}

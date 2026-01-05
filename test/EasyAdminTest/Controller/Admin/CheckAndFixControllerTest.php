<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Check and Fix Controller.
 */
class CheckAndFixControllerTest extends AbstractHttpControllerTestCase
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
     * Test check and fix index page is accessible.
     */
    public function testCheckAndFixIndexAction(): void
    {
        $this->dispatch('/admin/easy-admin/check-and-fix');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('EasyAdmin\Controller\Admin\CheckAndFix');
        $this->assertActionName('index');
    }

    /**
     * Test check and fix page requires authentication.
     */
    public function testCheckAndFixRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/check-and-fix');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test check and fix page contains form.
     */
    public function testCheckAndFixContainsForm(): void
    {
        $this->dispatch('/admin/easy-admin/check-and-fix');

        $this->assertResponseStatusCode(200);
        $this->assertQuery('#check-and-fix-form');
    }
}

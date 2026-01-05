<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Addons Controller.
 */
class AddonsControllerTest extends AbstractHttpControllerTestCase
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
     * Test addons index page is accessible.
     */
    public function testAddonsIndexAction(): void
    {
        $this->dispatch('/admin/easy-admin/addons');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('EasyAdmin\Controller\Admin\Addons');
        $this->assertActionName('index');
    }

    /**
     * Test addons page requires authentication.
     */
    public function testAddonsRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/addons');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }
}

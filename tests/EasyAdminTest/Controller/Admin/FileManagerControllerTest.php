<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin File Manager Controller.
 */
class FileManagerControllerTest extends AbstractHttpControllerTestCase
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
     * Test file manager browse action is accessible.
     */
    public function testFileManagerBrowseAction(): void
    {
        $this->dispatch('/admin/easy-admin/file-manager');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('EasyAdmin\Controller\Admin\FileManager');
        $this->assertActionName('browse');
    }

    /**
     * Test file manager page requires authentication.
     */
    public function testFileManagerRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/file-manager');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test browse action with explicit browse path.
     */
    public function testFileManagerBrowseExplicitAction(): void
    {
        $this->dispatch('/admin/easy-admin/file-manager/browse');

        // Either 200 (success) or redirect.
        $this->assertTrue(
            $this->getResponse()->getStatusCode() === 200
            || $this->getResponse()->getStatusCode() === 302
        );
    }
}

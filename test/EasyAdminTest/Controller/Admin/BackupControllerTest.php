<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Backup Controller.
 */
class BackupControllerTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    /**
     * @var string
     */
    protected $backupDir;

    /**
     * @var string|null
     */
    protected $testBackupFile;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->backupDir = $this->getBasePath() . '/backup';
    }

    public function tearDown(): void
    {
        // Clean up test backup file if created.
        if ($this->testBackupFile && file_exists($this->testBackupFile)) {
            @unlink($this->testBackupFile);
        }
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test backup index page is accessible.
     */
    public function testBackupIndexAction(): void
    {
        $this->dispatch('/admin/easy-admin/backup');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('EasyAdmin\Controller\Admin\Backup');
        $this->assertActionName('index');
    }

    /**
     * Test backup page requires authentication.
     */
    public function testBackupRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/backup');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test backup page contains database backup section.
     */
    public function testBackupContainsDatabaseSection(): void
    {
        $this->dispatch('/admin/easy-admin/backup');

        $this->assertResponseStatusCode(200);
        $this->assertQuery('form[action*="backup"]');
    }

    /**
     * Test backup page contains files backup section.
     */
    public function testBackupContainsFilesSection(): void
    {
        $this->dispatch('/admin/easy-admin/backup');

        $this->assertResponseStatusCode(200);
        $this->assertQueryContentContains('h2', 'Files backup');
    }

    /**
     * Test download action requires file parameter.
     */
    public function testDownloadRequiresFileParam(): void
    {
        $this->dispatch('/admin/easy-admin/backup/download');

        // Should redirect back to index with error.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test download action rejects non-existent files.
     */
    public function testDownloadRejectsNonExistentFile(): void
    {
        $this->dispatch('/admin/easy-admin/backup/download?file=nonexistent.sql');

        // Should redirect back to index with error.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test download action rejects directory traversal.
     */
    public function testDownloadRejectsDirectoryTraversal(): void
    {
        $this->dispatch('/admin/easy-admin/backup/download?file=../../../config/database.ini');

        // Should redirect back to index with error (file not in backup dir).
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test download action requires authentication.
     */
    public function testDownloadRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/backup/download?file=test.sql');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test download action validates file exists.
     */
    public function testDownloadValidatesFile(): void
    {
        // Create backup directory with a test file.
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        $this->testBackupFile = $this->backupDir . '/test-download.sql';
        file_put_contents($this->testBackupFile, '-- Test SQL');

        // Reset the application for fresh dispatch.
        $this->reset();
        $this->loginAdmin();

        // Dispatch - SendFile will output directly, capture it.
        ob_start();
        $this->dispatch('/admin/easy-admin/backup/download?file=test-download.sql');
        $content = ob_get_clean();

        // Should contain the file content or redirect.
        $statusCode = $this->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === 200 || strpos($content, '-- Test SQL') !== false,
            "Expected 200 status or file content"
        );
    }

    /**
     * Test delete-confirm action with existing file.
     */
    public function testDeleteConfirmWithFile(): void
    {
        // Create backup directory with a test file.
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        $this->testBackupFile = $this->backupDir . '/test-confirm.sql';
        file_put_contents($this->testBackupFile, '-- Test SQL');

        // Reset for fresh dispatch.
        $this->reset();
        $this->loginAdmin();

        $this->dispatch('/admin/easy-admin/backup/delete-confirm?filename=test-confirm.sql');

        $this->assertResponseStatusCode(200);
    }
}

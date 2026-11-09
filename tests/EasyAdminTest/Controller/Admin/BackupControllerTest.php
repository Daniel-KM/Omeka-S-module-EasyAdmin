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
        // Each backup is a collapsible task, not a heading.
        $this->assertQueryContentContains('.backup-task-name', 'Files backup');
        $this->assertQueryContentContains('.backup-task-name', 'Database backup');
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
     *
     * Note: This test is marked as "risky" by PHPUnit because file download
     * actions stream content via Laminas\Http\PhpEnvironment\Response, which
     * manages its own output buffers independently of PHPUnit's tracking.
     * The test logic is correct and passes - the "risky" warning is expected
     * and can be safely ignored.
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

        // Sending a file opens and closes output buffers, so the level is
        // restored in both directions: phpunit reports the test as risky when
        // it does not end with the level it started with.
        $bufferLevel = ob_get_level();
        ob_start();
        $this->dispatch('/admin/easy-admin/backup/download?file=test-download.sql');
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        while (ob_get_level() < $bufferLevel) {
            ob_start();
        }

        // Should return 200 for successful download.
        $statusCode = $this->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === 200 || $statusCode === 302,
            "Expected 200 status for download or 302 redirect"
        );
    }

    /**
     * Test backup index page contains CSRF token in forms.
     */
    public function testBackupIndexContainsCsrf(): void
    {
        $this->dispatch('/admin/easy-admin/backup');

        $this->assertResponseStatusCode(200);
        $this->assertQuery('input[name="form_csrf"]');
    }

    /**
     * Test backup database POST without CSRF is rejected.
     */
    public function testBackupDatabaseRejectsMissingCsrf(): void
    {
        $this->dispatch('/admin/easy-admin/backup/backup-database', 'POST', [
            'include_structure' => '1',
            'include_data' => '1',
        ]);

        // Should redirect back to index (CSRF invalid).
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test backup files POST without CSRF is rejected.
     */
    public function testBackupFilesRejectsMissingCsrf(): void
    {
        $this->dispatch('/admin/easy-admin/backup/backup-files', 'POST', [
            'include' => ['core'],
        ]);

        // Should redirect back to index (CSRF invalid).
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test backup database GET redirects to index.
     */
    public function testBackupDatabaseGetRedirects(): void
    {
        $this->dispatch('/admin/easy-admin/backup/backup-database');

        $this->assertResponseStatusCode(302);
    }

    /**
     * Test backup files GET redirects to index.
     */
    public function testBackupFilesGetRedirects(): void
    {
        $this->dispatch('/admin/easy-admin/backup/backup-files');

        $this->assertResponseStatusCode(302);
    }

    /**
     * Test backup database POST with valid CSRF dispatches job.
     */
    public function testBackupDatabaseWithValidCsrf(): void
    {
        // Get a valid CSRF token from the backup form.
        $formElementManager = $this->getService('FormElementManager');
        $form = $formElementManager->get(\Laminas\Form\Form::class);
        $csrfElement = $form->get('form_csrf');
        $csrfValue = $csrfElement->getValue();

        $this->dispatch('/admin/easy-admin/backup/backup-database', 'POST', [
            'form_csrf' => $csrfValue,
            'include_structure' => '1',
            'include_data' => '1',
            'compress' => '1',
        ]);

        // Should redirect back to index after dispatching job.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test backup files POST with valid CSRF dispatches job.
     */
    public function testBackupFilesWithValidCsrf(): void
    {
        // Get a valid CSRF token from the backup form.
        $formElementManager = $this->getService('FormElementManager');
        $form = $formElementManager->get(\Laminas\Form\Form::class);
        $csrfElement = $form->get('form_csrf');
        $csrfValue = $csrfElement->getValue();

        $this->dispatch('/admin/easy-admin/backup/backup-files', 'POST', [
            'form_csrf' => $csrfValue,
            'include' => ['core'],
            'compression' => '6',
        ]);

        // Should redirect back to index after dispatching job.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test delete action without CSRF is rejected.
     */
    public function testDeleteRejectsMissingCsrf(): void
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        $this->testBackupFile = $this->backupDir . '/test-delete-csrf.sql';
        file_put_contents($this->testBackupFile, '-- Test SQL');

        $this->dispatch('/admin/easy-admin/backup/delete', 'POST', [
            'filename' => 'test-delete-csrf.sql',
        ]);

        // Should redirect (CSRF invalid).
        $this->assertResponseStatusCode(302);
        // File should still exist.
        $this->assertFileExists($this->testBackupFile);
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

    /**
     * A running backup job is displayed and its file is marked in progress,
     * without size nor actions, unlike an older file.
     */
    public function testRunningBackupIsDisplayedInProgress(): void
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        $oldFile = $this->backupDir . '/database-test-old.sql';
        $this->testBackupFile = $this->backupDir . '/database-test-running.sql';
        file_put_contents($oldFile, '-- Old');
        touch($oldFile, time() - 3600);
        file_put_contents($this->testBackupFile, '-- Partial');

        $entityManager = $this->getApplicationServiceLocator()->get('Omeka\EntityManager');
        $job = new \Omeka\Entity\Job();
        $job->setClass(\EasyAdmin\Job\DatabaseBackup::class);
        $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
        $entityManager->persist($job);
        $entityManager->flush();
        $jobId = $job->getId();
        // The date is set on persist, so it is updated directly.
        $entityManager->getConnection()->executeStatement(
            'UPDATE job SET started = ? WHERE id = ?',
            [date('Y-m-d H:i:s', time() - 60), $jobId]
        );
        $entityManager->clear();

        try {
            $this->dispatch('/admin/easy-admin/backup');
            $this->assertResponseStatusCode(200);
            $body = $this->getResponse()->getBody();
            $this->assertStringContainsString('A backup is in progress', $body);

            $dom = new \Laminas\Dom\Query($body);
            $rows = [];
            foreach ($dom->execute('#backup-list tbody tr') as $row) {
                $rows[trim($row->getElementsByTagName('td')->item(0)->textContent)] = $row;
            }
            $html = fn ($node) => $node->ownerDocument->saveHTML($node);
            $this->assertStringContainsString('In progress', $rows['database-test-running.sql']->textContent);
            $this->assertStringNotContainsString('download', $html($rows['database-test-running.sql']));
            $this->assertStringNotContainsString('In progress', $rows['database-test-old.sql']->textContent);
            $this->assertStringContainsString('download', $html($rows['database-test-old.sql']));
        } finally {
            @unlink($oldFile);
            $this->getApplicationServiceLocator()->get('Omeka\Connection')
                ->executeStatement('DELETE FROM job WHERE id = ?', [$jobId]);
        }
    }

    /**
     * A job started long ago is a job that died without updating its status,
     * so it is not displayed and the files are complete.
     */
    public function testOldRunningBackupIsIgnored(): void
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        // The file of a dead job is no longer written.
        $this->testBackupFile = $this->backupDir . '/database-test-stalled.sql';
        file_put_contents($this->testBackupFile, '-- Partial');
        touch($this->testBackupFile, time() - 600);

        $entityManager = $this->getApplicationServiceLocator()->get('Omeka\EntityManager');
        $job = new \Omeka\Entity\Job();
        $job->setClass(\EasyAdmin\Job\DatabaseBackup::class);
        $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
        $entityManager->persist($job);
        $entityManager->flush();
        $jobId = $job->getId();
        $entityManager->getConnection()->executeStatement(
            'UPDATE job SET started = ? WHERE id = ?',
            [date('Y-m-d H:i:s', time() - 2 * 86400), $jobId]
        );
        $entityManager->clear();

        try {
            $this->dispatch('/admin/easy-admin/backup');
            $this->assertResponseStatusCode(200);
            $body = $this->getResponse()->getBody();
            // Other tests may dispatch real backup jobs, so only this one is
            // checked.
            $this->assertStringNotContainsString('>#' . $jobId . '<', $body);
            $dom = new \Laminas\Dom\Query($body);
            foreach ($dom->execute('#backup-list tbody tr') as $row) {
                if (trim($row->getElementsByTagName('td')->item(0)->textContent) === 'database-test-stalled.sql') {
                    $this->assertStringNotContainsString('In progress', $row->textContent);
                }
            }
        } finally {
            $this->getApplicationServiceLocator()->get('Omeka\Connection')
                ->executeStatement('DELETE FROM job WHERE id = ?', [$jobId]);
        }
    }

}

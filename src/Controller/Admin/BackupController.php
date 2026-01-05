<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use EasyAdmin\Job\Backup as BackupJob;
use EasyAdmin\Job\DatabaseBackup;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;

class BackupController extends AbstractActionController
{
    /**
     * @var string
     */
    protected $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function indexAction()
    {
        $backupDir = $this->basePath . '/backup';
        $backups = $this->getBackupList($backupDir);

        $formDelete = $this->getForm(ConfirmForm::class);
        $formDelete
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'delete'], true))
            ->setAttribute('id', 'confirm-delete')
            ->setButtonLabel('Confirm delete'); // @translate

        return new ViewModel([
            'backups' => $backups,
            'backupDir' => $backupDir,
            'formDelete' => $formDelete,
        ]);
    }

    public function backupDatabaseAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        // Get backup options.
        $compress = (bool) $this->params()->fromPost('compress', true);
        $includeStructure = (bool) $this->params()->fromPost('include_structure', true);
        $includeData = (bool) $this->params()->fromPost('include_data', true);
        $includeViews = (bool) $this->params()->fromPost('include_views', true);
        $includeRoutines = (bool) $this->params()->fromPost('include_routines', true);
        $includeTriggers = (bool) $this->params()->fromPost('include_triggers', true);
        $skipDataTables = $this->params()->fromPost('skip_data_tables', []);

        // Validate options.
        if (!$includeStructure && !$includeData) {
            $this->messenger()->addError(
                'At least one of "Structure" or "Data" must be selected.' // @translate
            );
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(DatabaseBackup::class, [
            'compress' => $compress,
            'include_structure' => $includeStructure,
            'include_data' => $includeData,
            'include_views' => $includeViews,
            'include_routines' => $includeRoutines,
            'include_triggers' => $includeTriggers,
            'skip_data_tables' => is_array($skipDataTables) ? $skipDataTables : [],
        ]);

        $this->messenger()->addSuccess(
            'Database backup started. Check job logs for progress.' // @translate
        );

        $this->messenger()->addSuccess(new \Common\Stdlib\PsrMessage(
            'Job #{job_id} started.', // @translate
            ['job_id' => $job->getId()]
        ));

        return $this->redirect()->toRoute(null, ['action' => 'index'], true);
    }

    public function backupFilesAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        $include = $this->params()->fromPost('include', []);
        if (!$include) {
            $this->messenger()->addError(
                'Please select at least one item to backup.' // @translate
            );
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        $compression = (int) $this->params()->fromPost('compression', 6);

        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(BackupJob::class, [
            'process' => 'backup_install',
            'include' => $include,
            'compression' => $compression,
        ]);

        $this->messenger()->addSuccess(
            'Files backup started. Check job logs for progress.' // @translate
        );

        $this->messenger()->addSuccess(new \Common\Stdlib\PsrMessage(
            'Job #{job_id} started.', // @translate
            ['job_id' => $job->getId()]
        ));

        return $this->redirect()->toRoute(null, ['action' => 'index'], true);
    }

    public function deleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        $filename = $this->params()->fromPost('filename');
        if (!$filename) {
            $this->messenger()->addError('No file specified.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        // Security: only allow deletion of files in backup directory.
        $backupDir = $this->basePath . '/backup';
        $filepath = $backupDir . '/' . basename($filename);

        if (!file_exists($filepath)) {
            $this->messenger()->addError('File not found.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        if (!is_file($filepath) || !is_writeable($filepath)) {
            $this->messenger()->addError('Cannot delete this file.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        if (@unlink($filepath)) {
            $this->messenger()->addSuccess('Backup deleted.'); // @translate
        } else {
            $this->messenger()->addError('Failed to delete backup.'); // @translate
        }

        return $this->redirect()->toRoute(null, ['action' => 'index'], true);
    }

    public function deleteConfirmAction()
    {
        $filename = $this->params()->fromQuery('filename');
        if (!$filename) {
            throw new \Laminas\Mvc\Exception\RuntimeException('No file specified.'); // @translate
        }

        $backupDir = $this->basePath . '/backup';
        $filepath = $backupDir . '/' . basename($filename);

        if (!file_exists($filepath) || !is_file($filepath)) {
            throw new \Laminas\Mvc\Exception\RuntimeException('File not found.'); // @translate
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'delete'], true));

        // Add hidden filename field.
        $form->add([
            'name' => 'filename',
            'type' => 'hidden',
            'attributes' => [
                'value' => $filename,
            ],
        ]);

        return (new ViewModel([
            'resource' => $filename,
            'resourceLabel' => 'backup', // @translate
            'form' => $form,
            'partialPath' => null,
        ]))->setTerminal(true);
    }

    /**
     * Download a backup file securely.
     *
     * Backup files contain sensitive data (database credentials, API keys,
     * user passwords) and must only be accessible to administrators.
     *
     * Uses SendFile plugin for streaming large files without memory issues,
     * and supports HTTP range requests for resumable downloads.
     */
    public function downloadAction()
    {
        $filename = $this->params()->fromQuery('file');
        if (!$filename) {
            $this->messenger()->addError('No file specified.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        // Security: use basename to prevent directory traversal.
        $safeFilename = basename($filename);
        $backupDir = $this->basePath . '/backup';
        $filepath = $backupDir . '/' . $safeFilename;

        // Verify the file is actually in the backup directory (paranoid check).
        $realBackupDir = realpath($backupDir);
        $realFilepath = realpath($filepath);
        if ($realBackupDir === false || $realFilepath === false
            || strpos($realFilepath, $realBackupDir . DIRECTORY_SEPARATOR) !== 0
        ) {
            $this->messenger()->addError('Invalid file path.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        // Determine content type based on extension.
        $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));
        $contentTypes = [
            'sql' => 'application/sql',
            'gz' => 'application/gzip',
            'zip' => 'application/zip',
            'tar' => 'application/x-tar',
            'bz2' => 'application/x-bzip2',
        ];

        // Use SendFile plugin for streaming large files.
        $response = $this->sendFile($filepath, [
            'content_type' => $contentTypes[$extension] ?? null,
            'filename' => $safeFilename,
            'disposition_mode' => 'attachment',
            'cache' => false,
        ]);

        if (!$response) {
            $this->messenger()->addError('File not found.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        return $response;
    }

    /**
     * Get list of backup files.
     */
    protected function getBackupList(string $backupDir): array
    {
        if (!is_dir($backupDir) || !is_readable($backupDir)) {
            return [];
        }

        $files = [];
        $entries = @scandir($backupDir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || mb_substr($entry, 0, 1) === '.') {
                continue;
            }

            $filepath = $backupDir . '/' . $entry;
            if (!is_file($filepath) || !is_readable($filepath)) {
                continue;
            }

            // Only show backup files.
            $extension = pathinfo($entry, PATHINFO_EXTENSION);
            if (!in_array($extension, ['zip', 'sql', 'gz', 'tar', 'bz2'])) {
                continue;
            }

            $files[] = [
                'name' => $entry,
                'path' => $filepath,
                'size' => filesize($filepath),
                'mtime' => filemtime($filepath),
                'type' => $this->getBackupType($entry),
            ];
        }

        // Sort by date descending (newest first).
        usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $files;
    }

    /**
     * Determine backup type from filename.
     */
    protected function getBackupType(string $filename): string
    {
        if (strpos($filename, 'database') !== false || strpos($filename, '.sql') !== false) {
            return 'database';
        }
        return 'files';
    }
}

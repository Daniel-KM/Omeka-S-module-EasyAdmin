<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use EasyAdmin\Controller\TraitEasyDir;
use EasyAdmin\Job\Backup as BackupJob;
use EasyAdmin\Job\DatabaseBackup;
use Laminas\Form\Form;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;

class BackupController extends AbstractActionController
{
    use TraitEasyDir;

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
        $runningJobs = $this->getRunningBackupJobs();
        $backups = $this->getBackupList($backupDir, $runningJobs);

        $formDelete = $this->getForm(ConfirmForm::class);
        $formDelete
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'delete'], true))
            ->setAttribute('id', 'confirm-delete')
            ->setButtonLabel('Confirm delete'); // @translate

        $formBackup = $this->getForm(Form::class);

        return new ViewModel([
            'backups' => $backups,
            'runningJobs' => $runningJobs,
            'backupDir' => $backupDir,
            'formDelete' => $formDelete,
            'formBackup' => $formBackup,
        ]);
    }

    public function backupDatabaseAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        $form = $this->getForm(Form::class);
        $form->setData($this->getRequest()->getPost());
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        // Get backup options. "compress" is now a gzip level (0 = none).
        $compressLevel = (int) $this->params()->fromPost('compress', 6);
        $compress = $compressLevel > 0;
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

        // Protect the backup directory before any file is written.
        $this->ensureDenyHtaccess($this->basePath . '/backup');

        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(DatabaseBackup::class, [
            'compress' => $compress,
            'compression_level' => $compressLevel,
            'include_structure' => $includeStructure,
            'include_data' => $includeData,
            'include_views' => $includeViews,
            'include_routines' => $includeRoutines,
            'include_triggers' => $includeTriggers,
            'skip_data_tables' => is_array($skipDataTables) ? $skipDataTables : [],
        ]);

        $urlPlugin = $this->url();
        $message = new \Common\Stdlib\PsrMessage(
            'Database backup started in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', htmlspecialchars($urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                    : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute(null, ['action' => 'index'], true);
    }

    public function backupFilesAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        $form = $this->getForm(Form::class);
        $form->setData($this->getRequest()->getPost());
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
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

        // Protect the backup directory before any file is written.
        $this->ensureDenyHtaccess($this->basePath . '/backup');

        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(BackupJob::class, [
            'process' => 'backup_install',
            'include' => $include,
            'compression' => $compression,
        ]);

        $urlPlugin = $this->url();
        $message = new \Common\Stdlib\PsrMessage(
            'Files backup started in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', htmlspecialchars($urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                    : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

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

        // Stream the file, confined to the backup directory.
        $filepath = $this->basePath . '/backup/' . basename($filename);
        $response = $this->sendPrivateFile($filepath, $this->basePath . '/backup');
        if (!$response) {
            $this->messenger()->addError('Invalid file path.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'index'], true);
        }

        return $response;
    }

    /**
     * Get the backup jobs that are running, the most recent first.
     *
     * The status of the job is checked with the process when the module Log
     * is available, else the status stored in the database is used.
     *
     * @return \Omeka\Entity\Job[]
     */
    protected function getRunningBackupJobs(): array
    {
        $entityManager = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\EntityManager');
        $jobs = $entityManager->getRepository(\Omeka\Entity\Job::class)->findBy(
            [
                'class' => [DatabaseBackup::class, BackupJob::class],
                'status' => [
                    \Omeka\Entity\Job::STATUS_STARTING,
                    \Omeka\Entity\Job::STATUS_IN_PROGRESS,
                    \Omeka\Entity\Job::STATUS_STOPPING,
                ],
            ],
            ['id' => 'DESC'],
            10
        );

        // A job that died without updating its status stays running forever,
        // so an old one is skipped.
        $jobs = array_values(array_filter($jobs, fn ($job) => $job->getStarted()
            && $job->getStarted()->getTimestamp() > time() - 86400));

        $plugins = $this->getPluginManager();
        if (!$plugins->has('jobState')) {
            return $jobs;
        }

        // A known state of the process that is not processing means that the
        // job is ended, even if its status was not updated.
        $jobState = $plugins->get('jobState');
        return array_values(array_filter($jobs, function ($job) use ($jobState) {
            $state = $job->getPid() ? $jobState($job) : null;
            return $state === null
                || !empty(\Log\Stdlib\JobState::STATES[$state]['processing']);
        }));
    }

    /**
     * Get list of backup files.
     *
     * A file whose type matches a running job, modified since the start of the
     * job and in the last minutes is being created, so its size is not the
     * final one. A file no longer modified belongs to a job that died.
     *
     * @param \Omeka\Entity\Job[] $runningJobs
     */
    protected function getBackupList(string $backupDir, array $runningJobs = []): array
    {
        $runningSince = [];
        foreach ($runningJobs as $job) {
            $type = $job->getClass() === DatabaseBackup::class ? 'database' : 'files';
            $started = $job->getStarted() ? $job->getStarted()->getTimestamp() : 0;
            $runningSince[$type] = isset($runningSince[$type]) ? min($runningSince[$type], $started) : $started;
        }

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

            $type = $this->getBackupType($entry);
            $mtime = filemtime($filepath);
            $files[] = [
                'name' => $entry,
                'path' => $filepath,
                'size' => filesize($filepath),
                'mtime' => $mtime,
                'type' => $type,
                'in_progress' => isset($runningSince[$type])
                    && $mtime >= $runningSince[$type]
                    && $mtime > time() - 300,
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

<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use EasyAdmin\Controller\TraitEasyDir;
use FilesystemIterator;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Permissions\Acl;

class FileManagerController extends AbstractActionController
{
    use TraitEasyDir;

    /**
     * @var \Omeka\Permissions\Acl
     */
    protected $acl;

    /**
     * @var bool
     */
    protected $allowAnyPath;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $baseUri;

    /**
     * @var string
     */
    protected $tempDir;

    public function __construct(
        Acl $acl,
        bool $allowAnyPath,
        string $basePath,
        ?string $baseUri,
        string $tempDir
    ) {
        $this->acl = $acl;
        $this->allowAnyPath = $allowAnyPath;
        $this->basePath = $basePath;
        $this->baseUri = $baseUri;
        $this->tempDir = $tempDir;
    }

    public function browseAction()
    {
        /**
         * @var \Omeka\Entity\User $user
         */
        $user = $this->identity();
        $settings = $this->settings();

        // Check omeka setting for files.
        if ($settings->get('disable_file_validation', false)) {
            $allowedMediaTypes = '';
            $allowedExtensions = '';
        } else {
            $allowedMediaTypes = $settings->get('media_type_whitelist', []);
            $allowedExtensions = $settings->get('extension_whitelist', []);
            $allowedMediaTypes = implode(',', $allowedMediaTypes);
            $allowedExtensions = implode(',', $allowedExtensions);
        }

        $allowEmptyFiles = (bool) $settings->get('easyadmin_allow_empty_files', false);

        $data = [
            // This option allows to manage resource form and bulk upload form.
            'data-bulk-upload' => true,
            'data-csrf' => (new \Laminas\Form\Element\Csrf('csrf'))->getValue(),
            'data-allowed-media-types' => $allowedMediaTypes,
            'data-allowed-extensions' => $allowedExtensions,
            'data-allow-empty-files' => (int) $allowEmptyFiles,
            'data-translate-pause' => $this->translate('Pause'), // @translate
            'data-translate-resume' => $this->translate('Resume'), // @translate
            'data-translate-no-file' => $this->translate('No files currently selected for upload'), // @translate
            'data-translate-invalid-file' => $allowEmptyFiles
                ? $this->translate('Not a valid file type or extension. Update your selection.') // @translate
                : $this->translate('Not a valid file type, extension or size. Update your selection.'), // @translate
            'data-translate-unknown-error' => $this->translate('An issue occurred.'), // @translate
        ];

        $returnQuery = $this->params()->fromQuery();
        unset($returnQuery['page']);

        $errorMessage = null;
        $currentPath = $this->params()->fromQuery('dir_path');
        $dirPath = $this->getAndCheckDirPath($currentPath, $errorMessage);

        if ($dirPath) {
            $fileIterator = new FilesystemIterator($dirPath);
            // TODO Use pagination.
            // $this->paginator($fileIterator->getTotalResults());
            // Get the specific part.
            // Note: default base uri and default base path use /files.
            $base = $this->baseUri ? rtrim($this->baseUri, '/') : rtrim($this->url()->fromRoute('top', []), '/') . '/files';
            $partPath = trim(mb_substr($dirPath, mb_strlen(rtrim($this->basePath, '/')) + 1), '/');
            $localUrl = $base . '/' . $partPath;

            /** @var \Omeka\Form\ConfirmForm $formDeleteSelected */
            $formDeleteSelected = $this->getForm(ConfirmForm::class);
            $formDeleteSelected
                ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'batch-delete'], ['query' => $returnQuery], true))
                ->setAttribute('id', 'confirm-delete-selected')
                ->setButtonLabel('Confirm Delete'); // @translate

            /** @var \Omeka\Form\ConfirmForm $formDeleteAll */
            $formDeleteAll = $this->getForm(ConfirmForm::class);
            $formDeleteAll
                ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'batch-delete-all'], ['query' => $returnQuery], true))
                ->setAttribute('id', 'confirm-delete-all')
                ->setButtonLabel('Confirm Delete'); // @translate
            $formDeleteAll
                ->get('submit')->setAttribute('disabled', true);
        } else {
            $localUrl = null;
            $fileIterator = null;

            $this->messenger()->addError($this->translate($errorMessage));
            $formDeleteSelected = null;
            $formDeleteAll = null;
        }

        $dirPaths = array_unique(array_filter(array_merge(array_values($settings->get('easyadmin_local_paths')) ?: [$dirPath], [$dirPath])));
        $dirPaths = array_combine($dirPaths, $dirPaths);

        return (new ViewModel([
            'basePath' => $this->basePath,
            'localUrl' => $localUrl,
            'dirPath' => $dirPath,
            'dirPaths' => $dirPaths,
            'isDirPathValid' => (bool) $dirPath,
            'data' => $data,
            'fileIterator' => $fileIterator,
            'formDeleteSelected' => $formDeleteSelected,
            'formDeleteAll' => $formDeleteAll,
            'isAdminUser' => $user ? $this->acl->isAdminRole($user->getRole()) : false,
            'returnQuery' => $returnQuery,
        ]));
    }

    public function deleteConfirmAction()
    {
        $errorMessage = null;
        $currentPath = $this->params()->fromQuery('path');
        $dirPath = $this->getAndCheckDirPath($currentPath, $errorMessage);
        if (!$dirPath) {
            throw new \Laminas\Mvc\Exception\RuntimeException($this->translate($errorMessage));
        }

        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $filename = $this->params()->fromQuery('filename');

        $errorMessage = null;
        $isFilenameValid = $this->checkFilename($filename, $errorMessage);
        if (!$isFilenameValid) {
            throw new \Laminas\Mvc\Exception\RuntimeException($this->translate($errorMessage));
        }

        $filepath = rtrim($dirPath, '//') . '/' . $filename;

        $errorMessage = null;
        $isFileValid = $this->checkFile($filepath, $errorMessage);
        if (!$isFileValid) {
            throw new \Laminas\Mvc\Exception\RuntimeException($this->translate($errorMessage));
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setAttribute('action', $this->url()->fromRoute('admin/easy-admin/file-manager', ['action' => 'delete'], ['query' => ['filename' => $filename]], true));

        return (new ViewModel([
                'resource' => $filename,
                'dirPath' => $dirPath,
                'file' => $filename,
                'resourceLabel' => 'file', // @translate
                'form' => $form,
                // 'partialPath' => 'easy-admin/admin/file-manager/show-details',
                'partialPath' => null,
                'linkTitle' => $linkTitle,
                'wrapSidebar' => false,
            ]))
            ->setTerminal(true);
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $dirPath = $this->params()->fromQuery('dir_path');
                $filename = $this->params()->fromQuery('filename');
                $this->checkAndDeleteFile($dirPath, $filename);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse'], true);
    }

    public function batchDeleteAction()
    {
        $returnQuery = $this->params()->fromQuery();

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $returnQuery], true);
        }

        // Use the post value, not the query.
        $errorMessage = null;
        $currentPath = $this->params()->fromPost('dir_path');
        $dirPath = $this->getAndCheckDirPath($currentPath, $errorMessage);
        if (!$dirPath) {
            $this->messenger()->addError('You must set a directory to delete files.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $returnQuery], true);
        }

        $filenames = $this->params()->fromPost('filenames', []);
        if (!$filenames) {
            $this->messenger()->addError('You must select at least one file to batch delete.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $returnQuery], true);
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $totalFiles = 0;
            foreach ($filenames as $filename) {
                $result = $this->checkAndDeleteFile($dirPath, $filename);
                if ($result) {
                    ++$totalFiles;
                }
            }
            if ($totalFiles) {
                $this->messenger()->addSuccess(new PsrMessage(
                    '{count} files were removed.', // @translate
                    ['count' => $totalFiles]
                ));
            } else {
                $this->messenger()->addWarning('No file removed.'); // @translate
            }
        } else {
            $this->messenger()->addFormErrors($form);
        }

        return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $returnQuery], true);
    }

    public function batchDeleteAllAction()
    {
        $returnQuery = $this->params()->fromQuery();

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $returnQuery], true);
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            // Use the post value, not the query.
            $errorMessage = null;
            $currentPath = $this->params()->fromPost('dir_path');
            $dirPath = $this->getAndCheckDirPath($currentPath, $errorMessage);
            if (!$dirPath) {
                $this->messenger()->addError($errorMessage ?: 'You must set a directory to delete files.'); // @translate
                return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $returnQuery], true);
            }

            $fileIterator = new FilesystemIterator($dirPath);
            $totalFiles = 0;
            foreach ($fileIterator as $file) {
                $filename = $file->getFilename();
                if (!$file->isFile()
                    || $file->isDir()
                    // || $file->isDot()
                    || !$file->isReadable()
                    || !$file->isWritable()
                ) {
                    continue;
                }
                $result = $this->checkAndDeleteFile($dirPath, $filename);
                if ($result) {
                    ++$totalFiles;
                }
            }
            if ($totalFiles) {
                $this->messenger()->addSuccess(new PsrMessage(
                    '{count} files were removed.', // @translate
                    ['count' => $totalFiles]
                ));
            } else {
                $this->messenger()->addWarning('No file removed.'); // @translate
            }
        } else {
            $this->messenger()->addFormErrors($form);
        }

        return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $returnQuery], true);
    }

    /**
     * Check and delete a file in a local path.
     */
    protected function checkAndDeleteFile(string $dirPath, string $filename): bool
    {
        $errorMessage = null;
        $dirPath = $this->getAndCheckDirPath($dirPath, $errorMessage);
        if ($dirPath === null) {
            $this->messenger()->addError($errorMessage);
            return false;
        }

        $errorMessage = null;
        $isFilenameValid = $this->checkFilename($filename, $errorMessage);
        if (!$isFilenameValid) {
            $this->messenger()->addError($errorMessage);
            return false;
        }

        $filepath = rtrim($dirPath, '//') . '/' . $filename;
        $fileExists = file_exists($filepath);
        if (!$fileExists) {
            return true;
        }

        if (is_dir($filepath)) {
            $this->messenger()->addError('It is forbidden to remove a folder.'); // @translate
            return false;
        }

        if (!is_writeable($filepath) || !is_file($filepath)) {
            $this->messenger()->addError('The file cannot be removed.'); // @translate
            return false;
        }

        $result = unlink($filepath);
        if ($result) {
            $this->messenger()->addSuccess('File successfully deleted'); // @translate
            return true;
        }

        $this->messenger()->addError('An issue occurred during deletion.'); // @translate
        return false;
    }
}

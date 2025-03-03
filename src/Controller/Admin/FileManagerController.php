<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use EasyAdmin\Controller\TraitEasyDir;
use FilesystemIterator;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Permissions\Acl;
use Common\Stdlib\PsrMessage;

class FileManagerController extends AbstractActionController
{
    use TraitEasyDir;

    /**
     * @var \Omeka\Permissions\Acl
     */
    protected $acl;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $tempDir;

    public function __construct(
        Acl $acl,
        string $basePath,
        string $tempDir
    ) {
        $this->acl = $acl;
        $this->basePath = $basePath;
        $this->tempDir = $tempDir;
    }

    public function browseAction()
    {
        // Check omeka setting for files.
        $settings = $this->settings();
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

        $errorMessage = null;
        $localPath = $this->getAndCheckLocalPath($errorMessage);
        if ($localPath) {
            $fileIterator = new FilesystemIterator($localPath);
            // TODO Use pagination.
            // $this->paginator($fileIterator->getTotalResults());
        } else {
            $fileIterator = null;
        }

        /** @var \Omeka\Entity\User $user */
        $user = $this->identity();

        $returnQuery = $this->params()->fromQuery();
        unset($returnQuery['page']);

        $errorMessage = null;
        $localPath = $this->getAndCheckLocalPath($errorMessage);
        if ($localPath) {
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
            $this->messenger()->addError($this->translate($errorMessage));
            $formDeleteSelected = null;
            $formDeleteAll = null;
        }

        return (new ViewModel([
            'localPath' => $localPath,
            'isLocalPathValid' => (bool) $localPath,
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
        $localPath = $this->getAndCheckLocalPath($errorMessage);
        if (!$localPath) {
            throw new \Laminas\Mvc\Exception\RuntimeException($this->translate($errorMessage));
        }

        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $filename = $this->params()->fromQuery('filename');
        $errorMessage = null;
        $isFilenameValid = $this->checkFilename($filename, $errorMessage);
        if (!$isFilenameValid) {
            throw new \Laminas\Mvc\Exception\RuntimeException($this->translate($errorMessage));
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setAttribute('action', $this->url()->fromRoute('admin/easy-admin/file-manager', ['action' => 'delete'], ['query' => ['filename' => $filename]], true));

        return (new ViewModel([
                'resource' => $filename,
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
                $filename = $this->params()->fromQuery('filename');
                $this->deleteFile($filename);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse'], true);
    }

    public function batchDeleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        $returnQuery = $this->params()->fromQuery();
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
                $result = $this->deleteFile($filename);
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
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $errorMessage = null;
            $localPath = $this->getAndCheckLocalPath($errorMessage);
            if ($localPath) {
                $fileIterator = new FilesystemIterator($localPath);
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
                    $result = $this->deleteFile($filename);
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
                $this->messenger()->addError($errorMessage ?: 'No local path.'); // @translate
            }
        } else {
            $this->messenger()->addFormErrors($form);
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $this->params()->fromQuery()], true);
    }

    /**
     * Delete a file in the local path.
     *
     * @param string $filename
     */
    protected function deleteFile(string $filename): bool
    {
        $errorMessage = null;
        $localPath = $this->getAndCheckLocalPath($errorMessage);
        if (!$localPath) {
            $this->messenger()->addError($errorMessage);
            return false;
        }

        $errorMessage = null;
        $isFilenameValid = $this->checkFilename($filename, $errorMessage);
        if (!$isFilenameValid) {
            $this->messenger()->addError($errorMessage);
            return false;
        }

        $filepath = rtrim($localPath, '//') . '/' . $filename;
        $fileExists = file_exists($filepath);
        if (!$fileExists) {
            return true;
        }

        if (!is_writeable($filepath) || !is_file($filepath) || is_dir($filepath)) {
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

<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use FilesystemIterator;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class FileManagerController extends AbstractActionController
{
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

        $localPath = $settings->get('easyadmin_local_path');

        return (new ViewModel([
            'data' => $data,
            'fileIterator' => $localPath && file_exists($localPath) && is_dir($localPath)
                ? new FilesystemIterator($localPath)
                : null,
        ]));
    }
}

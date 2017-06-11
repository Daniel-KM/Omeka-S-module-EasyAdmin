<?php
namespace EasyInstall\Controller\Admin;

use EasyInstall\Form\UploadForm;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Doctrine\Common\Inflector\Inflector;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;

class IndexController extends AbstractActionController
{

    public function indexAction()
    {
        $view = new ViewModel;

        $form = $this->getForm(UploadForm::class);
        $view->form = $form;

        $addons = $form->getAddons();
        if ($addons->isEmpty()) {
            $this->messenger()->addWarning(
                'No addon to list: check your connection.'); // @translate
            return $view;
        }

        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $view;
        }

        $form->setData($this->params()->fromPost());

        if (!$form->isValid()) {
            $this->messenger()->addError(
                'There was an error on the form. Please try again.'); // @translate
            return $view;
        }

        foreach ($addons->types() as $type) {
            $url = $this->params()->fromPost($type);
            if ($url) {
                $addon = $addons->dataForUrl($url, $type);
                if ($addons->dirExists($addon)) {
                    // Hack to get a clean message.
                    $type = str_replace('omeka', '', $type);
                    $this->messenger()->addError(new Message(
                        'The %s "%s" is already downloaded.', $type, $addon['name'])); // @translate
                    return $this->redirect()->toRoute(null, ['action' => 'index'], true);
                }
                $this->installAddon($addon);
                return $this->redirect()->toRoute(null, ['action' => 'index'], true);
            }
        }

        $this->messenger()->addError(
            'Nothing processed. Please try again.'); // @translate
        return $this->redirect()->toRoute(null, ['action' => 'index'], true);
    }

    /**
     * Helper to install an addon.
     *
     * @param array $addon
     * @return void
     */
    protected function installAddon(array $addon)
    {
        switch ($addon['type']) {
            case 'module':
                $destination = OMEKA_PATH . '/modules';
                $type = 'module';
                break;
            case 'theme':
                $destination = OMEKA_PATH . '/themes';
                $type = 'theme';
                break;
            default:
                return;
        }

        $isWriteableDestination = is_writeable($destination);
        if (!$isWriteableDestination) {
            $this->messenger()->addError(new Message(
                'The %s directory is not writeable by the server.', $type)); // @translate
            return;
        }
        // Add a message for security hole.
        $this->messenger()->addWarning(new Message(
            'Don’t forget to protect the %s directory from writing after installation.', $type)); // @translate


        // Local zip file path.
        $zipFile = $destination . DIRECTORY_SEPARATOR . basename($addon['zip']);
        if (file_exists($zipFile)) {
            $result = @unlink($zipFile);
            if (!$result) {
                $this->messenger()->addError(new Message(
                    'A zipfile exists with the same name in the %s directory and cannot be removed.', $type)); // @translate
                return;
            }
        }

        if (file_exists($destination . DIRECTORY_SEPARATOR . $addon['dir'])) {
            $this->messenger()->addError(new Message(
                'The %s directory "%s" already exists.', $type, $addon['dir'])); // @translate
            return;
        }

        // Get the zip file from server.
        $result = $this->downloadFile($addon['zip'], $zipFile);
        if (!$result) {
            $this->messenger()->addError(new Message(
                'Unable to fetch the %s "%s".', $type, $addon['name'])); // @translate
            return;
        }

        // Unzip downloaded file.
        $result = $this->unzipFile($zipFile, $destination);

        unlink($zipFile);

        if ($result) {
            $message = new Message('If "%s" doesn’t appear in the list of %s, its directory may need to be renamed.', // @translate
                $addon['name'], Inflector::pluralize($type));
            $result = $this->moveAddon($addon);
            $this->messenger()->add(
                $result ? Messenger::NOTICE : Messenger::WARNING,
                $message);
            $this->messenger()->addSuccess(new Message(
                '%s uploaded successfully', ucfirst($type))); // @translate
        }
        else {
            $this->messenger()->addError(new Message(
                'An error occurred during the unzipping of the %s "%s".', // @translate
                    $type, $addon['name']));
        }
    }

    /**
     * Helper to download a file.
     *
     * @param string $source
     * @param string $destination
     * @return boolean
     */
    protected function downloadFile($source, $destination)
    {
        $handle = @fopen($source, 'rb');
        if (empty($handle)) {
            return false;
        }
        $result = (boolean) file_put_contents($destination, $handle);
        @fclose($handle);
        return $result;
    }

    /**
     * Helper to unzip a file.
     *
     * @param string $source A local file.
     * @param string $destination A writeable dir.
     * @return boolean
     */
    protected function unzipFile($source, $destination)
    {
        // Unzip via php-zip.
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive;
            $result = $zip->open($source);
            if ($result === true) {
                $result = $zip->extractTo($destination);
                $zip->close();
            }
        }

        // Unzip via command line
        else {
            // Check if the zip command exists.
            try {
                $this->executeCommand('unzip', $status, $output, $errors);
            } catch (\Exception $e) {
                $status = 1;
            }
            // A return value of 0 indicates the convert binary is working correctly.
            $result = $status == 0;
            if ($result) {
                $command = 'unzip ' . escapeshellarg($source) . ' -d ' . escapeshellarg($destination);
                try {
                    $this->executeCommand($command, $status, $output, $errors);
                } catch (\Exception $e) {
                    $status = 1;
                }
                $result = $status == 0;
            }
        }

        return $result;
    }

    /**
     * Helper to rename the directory of an addon.
     *
     * @internal The name of the directory is unknown, because it is a subfolder
     * inside the zip file.
     * @todo Get the directory name from the zip.
     *
     * @param string $addon
     * @return boolean
     */
    protected function moveAddon($addon)
    {
        switch ($addon['type']) {
            case 'module':
                $destination = OMEKA_PATH . '/modules';
                break;
            case 'theme':
                $destination = OMEKA_PATH . '/themes';
                break;
            default:
                return;
        }

        // Allows to manage case like AddItemLink, where the project name on
        // github is only "AddItem".
        $loop = [$addon['dir']];
        if ($addon['basename'] != $addon['dir']) {
            $loop[] = $addon['basename'];
        }

        // Manage only the most common cases.
        // @todo Use a scan dir + a regex.
        $checks = [
            ['', ''],
            ['', '-master'],
            ['', '-module-master'],
            ['', '-theme-master'],
            ['omeka-', '-master'],
            ['omeka-s-', '-master'],
            ['omeka-S-', '-master'],
            ['module-', '-master'],
            ['module_', '-master'],
            ['omeka-module-', '-master'],
            ['omeka-s-module-', '-master'],
            ['omeka-S-module-', '-master'],
            ['theme-', '-master'],
            ['theme_', '-master'],
            ['omeka-theme-', '-master'],
            ['omeka-s-theme-', '-master'],
            ['omeka-S-theme-', '-master'],
            ['omeka_', '-master'],
            ['omeka_s_', '-master'],
            ['omeka_S_', '-master'],
            ['omeka_module_', '-master'],
            ['omeka_s_module_', '-master'],
            ['omeka_S_module_', '-master'],
            ['omeka_theme_', '-master'],
            ['omeka_s_theme_', '-master'],
            ['omeka_S_theme_', '-master'],
            ['omeka_Module_', '-master'],
            ['omeka_s_Module_', '-master'],
            ['omeka_S_Module_', '-master'],
            ['omeka_Theme_', '-master'],
            ['omeka_s_Theme_', '-master'],
            ['omeka_S_Theme_', '-master'],
        ];

        $name = '';
        foreach ($loop as $addonName) {
            foreach ($checks as $check) {
                $checkName = $destination . DIRECTORY_SEPARATOR
                    . $check[0] . $addonName . $check[1];
                if (file_exists($checkName)) {
                    $name = $checkName;
                    break 2;
                }
                // Allows to manage case like name is "Ead", not "EAD".
                $checkName = $destination . DIRECTORY_SEPARATOR
                    . $check[0] . ucfirst(strtolower($addonName)) . $check[1];
                if (file_exists($checkName)) {
                    $name = $checkName;
                    $addonName = ucfirst(strtolower($addonName));
                    break 2;
                }
                if ($check[0]) {
                    $checkName = $destination . DIRECTORY_SEPARATOR
                        . ucfirst($check[0]) . $addonName . $check[1];
                    if (file_exists($checkName)) {
                        $name = $checkName;
                        break 2;
                    }
                    $checkName = $destination . DIRECTORY_SEPARATOR
                        . ucfirst($check[0]) . ucfirst(strtolower($addonName)) . $check[1];
                    if (file_exists($checkName)) {
                        $name = $checkName;
                        $addonName = ucfirst(strtolower($addonName));
                        break 2;
                    }
                }
            }
        }

        if (empty($name)) {
            return false;
        }

        $path = $destination . DIRECTORY_SEPARATOR . $addon['dir'];
        return rename($name, $path);
    }

    /**
     * Execute a shell command without exec().
     *
     * @see Omeka_File_Derivative_Strategy_ExternalImageMagick::executeCommand()
     *
     * @param string $command
     * @param integer $status
     * @param string $output
     * @param array $errors
     * @throws Exception
     */
    protected function executeCommand($command, &$status, &$output, &$errors)
    {
        // Using proc_open() instead of exec() solves a problem where exec('convert')
        // fails with a "Permission Denied" error because the current working
        // directory cannot be set properly via exec().  Note that exec() works
        // fine when executing in the web environment but fails in CLI.
        $descriptorSpec = [
            0 => ['pipe', 'r'], //STDIN
            1 => ['pipe', 'w'], //STDOUT
            2 => ['pipe', 'w'], //STDERR
        ];
        if ($proc = proc_open($command, $descriptorSpec, $pipes, getcwd())) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($proc);
        } else {
            throw new \Exception(new Message(
                'Failed to execute command: %s', $command));
        }
    }
}

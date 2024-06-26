<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Doctrine\Inflector\InflectorFactory;
use EasyAdmin\Form\AddonsForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\ModuleRepresentation;
use Omeka\Mvc\Controller\Plugin\Messenger;

class AddonsController extends AbstractActionController
{
    public function indexAction()
    {
        $view = new ViewModel;

        /** @var \EasyAdmin\Form\AddonsForm $form */
        $form = $this->getForm(AddonsForm::class);
        $view->form = $form;

        $addons = $form->getAddons();
        if ($addons->isEmpty()) {
            $this->messenger()->addWarning(
                'No addon to list: check your connection.' // @translate
            );
            return $view;
        }

        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $view;
        }

        $form->setData($this->params()->fromPost());

        if (!$form->isValid()) {
            $this->messenger()->addError(
                'There was an error on the form. Please try again.' // @translate
            );
            return $view;
        }

        $data = $form->getData();

        foreach ($addons->types() as $type) {
            $url = $data[$type] ?? null;
            if ($url) {
                $addon = $addons->dataForUrl($url, $type);
                if ($addons->dirExists($addon)) {
                    // Hack to get a clean message.
                    $type = str_replace('omeka', '', $type);
                    $this->messenger()->addError(new PsrMessage(
                        'The {type} "{name}" is already downloaded.', // @translate
                        ['type' => $type, 'name' => $addon['name']]
                    ));
                    return $this->redirect()->toRoute(null, ['action' => 'index'], true);
                }
                $this->installAddon($addon);
                return $this->redirect()->toRoute(null, ['action' => 'index'], true);
            }
        }

        $this->messenger()->addError(
            'Nothing processed. Please try again.' // @translate
        );
        return $this->redirect()->toRoute(null, ['action' => 'index'], true);
    }

    /**
     * Helper to install an addon.
     *
     * @param array $addon
     */
    protected function installAddon(array $addon): void
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

        $missingDependencies = [];
        if (!empty($addon['dependencies'])) {
            foreach ($addon['dependencies'] as $dependency) {
                $module = $this->getModule($dependency);
                if (empty($module)
                    || (
                        $dependency !== 'Generic'
                        && $module->getJsonLd()['o:state'] !== \Omeka\Module\Manager::STATE_ACTIVE
                    )
                ) {
                    $missingDependencies[] = $dependency;
                }
            }
        }
        if ($missingDependencies) {
            $this->messenger()->addError(new PsrMessage(
                'The module "{module}" requires the dependencies "{names}" installed and enabled first.', // @translate
                ['module' => $addon['name'], 'names' => implode('", "', $missingDependencies)]
            ));
            return;
        }

        $isWriteableDestination = is_writeable($destination);
        if (!$isWriteableDestination) {
            $this->messenger()->addError(new PsrMessage(
                'The {type} directory is not writeable by the server.', // @translate
                ['type' => $type]
            ));
            return;
        }
        // Add a message for security hole.
        $this->messenger()->addWarning(new PsrMessage(
            'Don’t forget to protect the {type} directory from writing after installation.', // @translate
            ['type' => $type]
        ));

        // Local zip file path.
        $zipFile = $destination . DIRECTORY_SEPARATOR . basename($addon['zip']);
        if (file_exists($zipFile)) {
            $result = @unlink($zipFile);
            if (!$result) {
                $this->messenger()->addError(new PsrMessage(
                    'A zipfile exists with the same name in the {type} directory and cannot be removed.', // @translate
                    ['type' => $type]
                ));
                return;
            }
        }

        if (file_exists($destination . DIRECTORY_SEPARATOR . $addon['dir'])) {
            $this->messenger()->addError(new PsrMessage(
                'The {type} directory "{name}" already exists.', // @translate
                ['type' => $type, 'name' => $addon['dir']]
            ));
            return;
        }

        // Get the zip file from server.
        $result = $this->downloadFile($addon['zip'], $zipFile);
        if (!$result) {
            $this->messenger()->addError(new PsrMessage(
                'Unable to fetch the {type} "{name}".', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            return;
        }

        // Unzip downloaded file.
        $result = $this->unzipFile($zipFile, $destination);

        unlink($zipFile);

        if (!$result) {
            $this->messenger()->addError(new PsrMessage(
                'An error occurred during the unzipping of the {type} "{name}".', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            return;
        }

        // Move the addon to its destination.
        $result = $this->moveAddon($addon);

        // Check the special case of dependency Generic to avoid a fatal error.
        // This is used only for modules downloaded from omeka.org, since the
        // dependencies are not available here.
        // TODO Get the dependencies for the modules on omeka.org.
        if ($type === 'module') {
            $moduleFile = $destination . DIRECTORY_SEPARATOR . $addon['dir'] . DIRECTORY_SEPARATOR . 'Module.php';
            if (file_exists($moduleFile) && filesize($moduleFile)) {
                $modulePhp = file_get_contents($moduleFile);
                if (strpos($modulePhp, 'use Generic\AbstractModule;')) {
                    /** @var \Omeka\Api\Representation\ModuleRepresentation @module */
                    $module = $this->getModule('Generic');
                    if (empty($module)
                        || version_compare($module->getJsonLd()['o:ini']['version'] ?? '', '3.4.43', '<')
                    ) {
                        $this->messenger()->addError(new PsrMessage(
                            'The module "{name}" requires the dependency "Generic" version "{version}" available first.', // @translate
                            ['name' => $addon['name'], 'version' => '3.4.43']
                        ));
                        // Remove the folder to avoid a fatal error (Generic is a
                        // required abstract class).
                        $this->rmDir($destination . DIRECTORY_SEPARATOR . $addon['dir']);
                        return;
                    }
                }
            }
        }

        $message = new PsrMessage(
            'If "{name}" doesn’t appear in the list of {type}, its directory may need to be renamed.', // @translate
            ['name' => $addon['name'], 'type' => InflectorFactory::create()->build()->pluralize($type)]
        );
        $this->messenger()->add(
            $result ? Messenger::NOTICE : Messenger::WARNING,
            $message
        );
        $this->messenger()->addSuccess(new PsrMessage(
            '{type} uploaded successfully', // @translate
            ['type' => ucfirst($type)]
        ));

        $this->messenger()->addNotice(new PsrMessage(
            'It is always recommended to read the original readme or help of the addon.' // @translate
        ));
    }

    /**
     * Helper to download a file.
     *
     * @param string $source
     * @param string $destination
     * @return bool
     */
    protected function downloadFile($source, $destination)
    {
        $handle = @fopen($source, 'rb');
        if (empty($handle)) {
            return false;
        }
        $result = (bool) file_put_contents($destination, $handle);
        @fclose($handle);
        return $result;
    }

    /**
     * Helper to unzip a file.
     *
     * @param string $source A local file.
     * @param string $destination A writeable dir.
     * @return bool
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
                $status = $output = $errors = null;
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
     * The name of the directory is unknown, because it is a subfolder inside
     * the zip file, and the name of the module may be different from the name
     * of the directory.
     * @todo Get the directory name from the zip.
     *
     * @param string $addon
     * @return bool
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
                return false;
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

        $source = '';
        foreach ($loop as $addonName) {
            foreach ($checks as $check) {
                $sourceCheck = $destination . DIRECTORY_SEPARATOR
                    . $check[0] . $addonName . $check[1];
                if (file_exists($sourceCheck)) {
                    $source = $sourceCheck;
                    break 2;
                }
                // Allows to manage case like name is "Ead", not "EAD".
                $sourceCheck = $destination . DIRECTORY_SEPARATOR
                    . $check[0] . ucfirst(strtolower($addonName)) . $check[1];
                if (file_exists($sourceCheck)) {
                    $source = $sourceCheck;
                    $addonName = ucfirst(strtolower($addonName));
                    break 2;
                }
                if ($check[0]) {
                    $sourceCheck = $destination . DIRECTORY_SEPARATOR
                        . ucfirst($check[0]) . $addonName . $check[1];
                    if (file_exists($sourceCheck)) {
                        $source = $sourceCheck;
                        break 2;
                    }
                    $sourceCheck = $destination . DIRECTORY_SEPARATOR
                        . ucfirst($check[0]) . ucfirst(strtolower($addonName)) . $check[1];
                    if (file_exists($sourceCheck)) {
                        $source = $sourceCheck;
                        $addonName = ucfirst(strtolower($addonName));
                        break 2;
                    }
                }
            }
        }

        if ($source === '') {
            return false;
        }

        $path = $destination . DIRECTORY_SEPARATOR . $addon['dir'];
        if ($source === $path) {
            return true;
        }

        return rename($source, $path);
    }

    /**
     * Get a module by its name.
     *
     * @todo Modules cannot be api read or fetch one by one by the api (core issue).
     */
    protected function getModule(string $module): ?ModuleRepresentation
    {
        /** @var \Omeka\Api\Representation\ModuleRepresentation[] $modules */
        $modules = $this->api()->search('modules', ['id' => $module])->getContent();
        return $modules[$module] ?? null;
    }

    /**
     * Execute a shell command without exec().
     *
     * @see \Omeka\Stdlib\Cli::send()
     *
     * @param string $command
     * @param int $status
     * @param string $output
     * @param array $errors
     * @throws \Exception
     */
    protected function executeCommand($command, &$status, &$output, &$errors): void
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
        $pipes = [];
        if ($proc = proc_open($command, $descriptorSpec, $pipes, getcwd())) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($proc);
        } else {
            throw new \Exception((string) new PsrMessage(
                'Failed to execute command: {command}', // @translate
                ['command' => $command]
            ));
        }
    }

    /**
     * Remove a dir from filesystem.
     *
     * @param string $dirpath Absolute path.
     */
    private function rmDir(string $dirPath): bool
    {
        if (!file_exists($dirPath)) {
            return true;
        }
        if (strpos($dirPath, '/..') !== false || substr($dirPath, 0, 1) !== '/') {
            return false;
        }
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dirPath);
    }
}

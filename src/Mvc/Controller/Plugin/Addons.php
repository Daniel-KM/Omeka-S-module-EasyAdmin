<?php declare(strict_types=1);

namespace EasyAdmin\Mvc\Controller\Plugin;

use Common\Stdlib\PsrMessage;
use Doctrine\Inflector\InflectorFactory;
use Exception;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\Client\Adapter\Exception\RuntimeException;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Session\Container;
use Laminas\Uri\Http as HttpUri;
use Omeka\Api\Representation\ModuleRepresentation;
use Omeka\Mvc\Controller\Plugin\Api;
use Omeka\Mvc\Controller\Plugin\Messenger;
use ZipArchive;

/**
 * Manage addons for Omeka.
 */
class Addons extends AbstractPlugin
{
    /**
     * @var \Omeka\Mvc\Controller\Plugin\Api
     */
    protected $api;

    /**
     * @var \Laminas\Http\Client
     */
    protected $httpClient;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Messenger
     */
    protected $messenger;

    /**
     * Source of data and destination of addons.
     *
     * @var array
     */
    protected $data = [
        'omekamodule' => [
            'source' => 'https://omeka.org/add-ons/json/s_module.json',
            'destination' => '/modules',
        ],
        'omekatheme' => [
            'source' => 'https://omeka.org/add-ons/json/s_theme.json',
            'destination' => '/themes',
        ],
        'module' => [
            'source' => 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_modules.csv',
            'destination' => '/modules',
        ],
        'theme' => [
            'source' => 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_themes.csv',
            'destination' => '/themes',
        ],
    ];

    /**
     * Cache for the list of addons.
     *
     * @var array
     */
    protected $addons = [];

    /**
     * Expiration hops.
     *
     * @var int
     */
    protected $expirationHops = 10;

    /**
     * Expiration seconds.
     *
     * @var int
     */
    protected $expirationSeconds = 3600;

    public function __construct(
        Api $api,
        HttpClient $httpClient,
        Messenger $messenger
    ) {
        $this->api = $api;
        $this->httpClient = $httpClient;
        $this->messenger = $messenger;
    }

    /**
     * Return the addon list.
     *
     * @return array
     */
    public function __invoke(): self
    {
        return $this;
    }

    public function getLists(bool $refresh = false): array
    {
        // Build the list of addons only once.
        if (!$refresh && !$this->isEmpty()) {
            return $this->addons;
        }

        // Check the cache.
        $container = new Container('EasyAdmin');
        if (!$refresh && !isset($container->addons)) {
            $this->addons = $container->addons;
            if (!$this->isEmpty()) {
                return $this->addons;
            }
        }

        $this->addons = [];
        foreach ($this->types() as $addonType) {
            $this->addons[$addonType] = $this->listAddonsForType($addonType);
        }

        $container->addons = $this->addons;
        $container
            ->setExpirationSeconds($this->expirationSeconds)
            ->setExpirationHops($this->expirationHops);

        return $this->addons;
    }

    /**
     * Get curated selections of modules from the web.
     */
    public function getSelections(): array
    {
        $csv = @file_get_contents('https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/refs/heads/master/_data/omeka_s_selections.csv');
        $selections = [];
        if ($csv) {
            // Get the column for name and modules.
            $headers = [];
            $isFirst = true;
            foreach (explode("\n", $csv) as $row) {
                $row = str_getcsv($row) ?: [];
                if ($isFirst) {
                    $headers = array_flip($row);
                    $isFirst = false;
                } elseif ($row) {
                    $name = $row[$headers['Name']] ?? '';
                    if ($name) {
                        $selections[$name] = array_map('trim', explode(',', $row[$headers['Modules and themes']] ?? ''));
                    }
                }
            }
        }
        return $selections;
    }

    /**
     * Check if the lists of addons are empty.
     */
    public function isEmpty(): bool
    {
        if (empty($this->addons)) {
            return true;
        }
        foreach ($this->addons as $addons) {
            if (!empty($addons)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the list of default types.
     */
    public function types(): array
    {
        return array_keys($this->data);
    }

    /**
     * Get addon data from the namespace of the module.
     */
    public function dataFromNamespace(string $namespace, ?string $type = null): array
    {
        $list = $type
            ? (isset($this->addons[$type]) ? [$type => $this->addons[$type]] : [])
            : $this->addons;
        foreach ($list as $type => $addonsForType) {
            $addonsUrl = array_column($addonsForType, 'url', 'dir');
            if (isset($addonsUrl[$namespace]) && isset($addonsForType[$addonsUrl[$namespace]])) {
                return $addonsForType[$addonsUrl[$namespace]];
            }
        }
        return [];
    }

    /**
     * Get addon data from the url of the repository.
     */
    public function dataFromUrl(string $url, string $type): array
    {
        return $this->addons && isset($this->addons[$type][$url])
            ? $this->addons[$type][$url]
            : [];
    }

    /**
     * Check if an addon is installed.
     *
     * @param array $addon
     */
    public function dirExists($addon): bool
    {
        $destination = OMEKA_PATH . $this->data[$addon['type']]['destination'];
        $existings = $this->listDirsInDir($destination);
        $existings = array_map('strtolower', $existings);
        return in_array(strtolower($addon['dir']), $existings)
            || in_array(strtolower($addon['basename']), $existings);
    }

    /**
     * Helper to list the addons from a web page.
     *
     * @param string $type
     */
    protected function listAddonsForType($type): array
    {
        if (!isset($this->data[$type]['source'])) {
            return [];
        }
        $source = $this->data[$type]['source'];

        $content = $this->fileGetContents($source);
        if (empty($content)) {
            return [];
        }

        switch ($type) {
            case 'module':
            case 'theme':
                return $this->extractAddonList($content, $type);
            case 'omekamodule':
            case 'omekatheme':
                return $this->extractAddonListFromOmeka($content, $type);
        }
    }

    /**
     * Helper to get content from an external url.
     *
     * @param string $url
     */
    protected function fileGetContents($url): ?string
    {
        $uri = new HttpUri($url);
        $this->httpClient->reset();
        $this->httpClient->setUri($uri);
        try {
            $response = $this->httpClient->send();
            $response = $response->isOk() ? $response->getBody() : null;
        } catch (RuntimeException $e) {
            $response = null;
        }

        if (empty($response)) {
            $this->messenger->addError(new PsrMessage(
                'Unable to fetch the url {url}.', // @translate
                ['url' => $url]
            ));
        }

        return $response;
    }

    /**
     * Helper to parse a csv file to get urls and names of addons.
     *
     * @param string $csv
     * @param string $type
     */
    protected function extractAddonList($csv, $type): array
    {
        $list = [];

        $addons = array_map('str_getcsv', explode(PHP_EOL, $csv));
        $headers = array_flip($addons[0]);

        foreach ($addons as $key => $row) {
            if ($key == 0 || empty($row) || !isset($row[$headers['Url']])) {
                continue;
            }

            $url = $row[$headers['Url']];
            $name = $row[$headers['Name']];
            $version = $row[$headers['Last version']];
            $addonName = preg_replace('~[^A-Za-z0-9]~', '', $name);
            $dirname = $row[$headers['Directory name']] ?: $addonName;
            $server = strtolower(parse_url($url, PHP_URL_HOST));
            $dependencies = empty($headers['Dependencies']) || empty($row[$headers['Dependencies']])
                ? []
                : array_filter(array_map('trim', explode(',', $row[$headers['Dependencies']])));

            $zip = $row[$headers['Last released zip']];
            // Warning: the url with master may not have dependencies.
            if (!$zip) {
                switch ($server) {
                    case 'github.com':
                        $zip = $url . '/archive/master.zip';
                        break;
                    case 'gitlab.com':
                        $zip = $url . '/repository/archive.zip';
                        break;
                    default:
                        $zip = $url . '/master.zip';
                        break;
                }
            }

            $addon = [];
            $addon['type'] = $type;
            $addon['server'] = $server;
            $addon['name'] = $name;
            $addon['basename'] = basename($url);
            $addon['dir'] = $dirname;
            $addon['version'] = $version;
            $addon['url'] = $url;
            $addon['zip'] = $zip;
            $addon['dependencies'] = $dependencies;

            $list[$url] = $addon;
        }

        return $list;
    }

    /**
     * Helper to parse html to get urls and names of addons.
     *
     * @todo Manage dependencies for addon from omeka.org.
     *
     * @param string $json
     * @param string $type
     */
    protected function extractAddonListFromOmeka($json, $type): array
    {
        $list = [];

        $addonsList = json_decode($json, true);
        if (!$addonsList) {
            return [];
        }

        foreach ($addonsList as $name => $data) {
            if (!$data) {
                continue;
            }

            $version = $data['latest_version'];
            $url = 'https://github.com/' . $data['owner'] . '/' . $data['repo'];
            // Warning: the url with master may not have dependencies.
            $zip = $data['versions'][$version]['download_url'] ?? $url . '/archive/master.zip';

            $addon = [];
            $addon['type'] = str_replace('omeka', '', $type);
            $addon['server'] = 'omeka.org';
            $addon['name'] = $name;
            $addon['basename'] = $data['dirname'];
            $addon['dir'] = $data['dirname'];
            $addon['version'] = $data['latest_version'];
            $addon['url'] = $url;
            $addon['zip'] = $zip;
            $addon['dependencies'] = [];

            $list[$url] = $addon;
        }

        return $list;
    }

    /**
     * Helper to install an addon.
     */
    public function installAddon(array $addon): bool
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
                return false;
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
            $this->messenger->addError(new PsrMessage(
                'The module "{module}" requires the dependencies "{names}" installed and enabled first.', // @translate
                ['module' => $addon['name'], 'names' => implode('", "', $missingDependencies)]
            ));
            return false;
        }

        $isWriteableDestination = is_writeable($destination);
        if (!$isWriteableDestination) {
            $this->messenger->addError(new PsrMessage(
                'The {type} directory is not writeable by the server.', // @translate
                ['type' => $type]
            ));
            return false;
        }
        // Add a message for security hole.
        $this->messenger->addWarning(new PsrMessage(
            'Don’t forget to protect the {type} directory from writing after installation.', // @translate
            ['type' => $type]
        ));

        // Local zip file path.
        $zipFile = $destination . DIRECTORY_SEPARATOR . basename($addon['zip']);
        if (file_exists($zipFile)) {
            $result = @unlink($zipFile);
            if (!$result) {
                $this->messenger->addError(new PsrMessage(
                    'A zipfile exists with the same name in the {type} directory and cannot be removed.', // @translate
                    ['type' => $type]
                ));
                return false;
            }
        }

        if (file_exists($destination . DIRECTORY_SEPARATOR . $addon['dir'])) {
            $this->messenger->addError(new PsrMessage(
                'The {type} directory "{name}" already exists.', // @translate
                ['type' => $type, 'name' => $addon['dir']]
            ));
            return false;
        }

        // Get the zip file from server.
        $result = $this->downloadFile($addon['zip'], $zipFile);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'Unable to fetch the {type} "{name}".', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            return false;
        }

        // Unzip downloaded file.
        $result = $this->unzipFile($zipFile, $destination);

        unlink($zipFile);

        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'An error occurred during the unzipping of the {type} "{name}".', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            return false;
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
                        || version_compare($module->getJsonLd()['o:ini']['version'] ?? '', '3.4.47', '<')
                    ) {
                        $this->messenger->addError(new PsrMessage(
                            'The module "{name}" requires the dependency "Generic" version "{version}" available first.', // @translate
                            ['name' => $addon['name'], 'version' => '3.4.47']
                            ));
                        // Remove the folder to avoid a fatal error (Generic is a
                        // required abstract class).
                        $this->rmDir($destination . DIRECTORY_SEPARATOR . $addon['dir']);
                        return false;
                    }
                }
            }
        }

        $message = new PsrMessage(
            'If "{name}" doesn’t appear in the list of {type}, its directory may need to be renamed.', // @translate
            ['name' => $addon['name'], 'type' => InflectorFactory::create()->build()->pluralize($type)]
        );
        $this->messenger->add(
            $result ? Messenger::NOTICE : Messenger::WARNING,
            $message
        );
        $this->messenger->addSuccess(new PsrMessage(
            '{type} uploaded successfully', // @translate
            ['type' => ucfirst($type)]
        ));

        $this->messenger->addNotice(new PsrMessage(
            'It is always recommended to read the original readme or help of the addon.' // @translate
        ));

        return true;
    }

    /**
     * Get a module by its name.
     *
     * @todo Modules cannot be api read or fetch one by one by the api (core issue).
     */
    protected function getModule(string $module): ?ModuleRepresentation
    {
        /** @var \Omeka\Api\Representation\ModuleRepresentation[] $modules */
        $modules = $this->api->search('modules', ['id' => $module])->getContent();
        return $modules[$module] ?? null;
    }

    /**
     * Helper to download a file.
     *
     * @param string $source
     * @param string $destination
     * @return bool
     */
    protected function downloadFile($source, $destination): bool
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
    protected function unzipFile($source, $destination): bool
    {
        // Unzip via php-zip.
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
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
            } catch (Exception $e) {
                $status = 1;
            }
            // A return value of 0 indicates the convert binary is working correctly.
            $result = $status == 0;
            if ($result) {
                $command = 'unzip ' . escapeshellarg($source) . ' -d ' . escapeshellarg($destination);
                try {
                    $this->executeCommand($command, $status, $output, $errors);
                } catch (Exception $e) {
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
    protected function moveAddon($addon): bool
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
     * List directories in a directory, not recursively.
     *
     * @param string $dir
     */
    protected function listDirsInDir($dir): array
    {
        static $dirs;

        if (isset($dirs[$dir])) {
            return $dirs[$dir];
        }

        if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }

        $list = array_filter(array_diff(scandir($dir), ['.', '..']), fn ($file) => is_dir($dir . DIRECTORY_SEPARATOR . $file));

        $dirs[$dir] = $list;
        return $dirs[$dir];
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
            throw new Exception((string) new PsrMessage(
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

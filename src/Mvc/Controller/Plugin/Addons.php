<?php declare(strict_types=1);

namespace EasyAdmin\Mvc\Controller\Plugin;

use Common\Stdlib\PsrMessage;
use Doctrine\Inflector\InflectorFactory;
use Exception;
use Laminas\Http\Client\Adapter\Curl as CurlAdapter;
use Laminas\Http\Client\Adapter\Exception\RuntimeException;
use Laminas\Http\Client as HttpClient;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Session\Container;
use Laminas\Uri\Http as HttpUri;
use Omeka\Api\Representation\ModuleRepresentation;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Mvc\Controller\Plugin\Api;
use Omeka\Mvc\Controller\Plugin\Messenger;
use ZipArchive;

/**
 * Manage addons for Omeka.
 *
 * A simplified version can be found in tool Install Omeka S.
 * @see https://daniel-km.github.io/UpgradeToOmekaS/omeka_s_install.html
 *
 * @todo This plugin can be simplified if the lists contain all the data.
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
     * @var \Omeka\Module\Manager
     */
    protected $moduleManager;

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
            'source' => 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_modules.json',
            'destination' => '/modules',
        ],
        'theme' => [
            'source' => 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_themes.json',
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

    /**
     * Cache for the list of selections.
     *
     * @var array
     */
    protected $selections = [];

    public function __construct(
        Api $api,
        HttpClient $httpClient,
        Messenger $messenger,
        ?ModuleManager $moduleManager = null
    ) {
        $this->api = $api;
        $this->httpClient = $httpClient;
        $this->messenger = $messenger;
        $this->moduleManager = $moduleManager;
    }

    public function __invoke(): self
    {
        return $this;
    }

    public function getAddons(bool $refresh = false): array
    {
        $this->initAddons($refresh);
        return $this->addons;
    }

    /**
     * Get curated selections of modules from the web.
     */
    public function getSelections(bool $refresh = false): array
    {
        // Build the list of selections only once.
        $isEmpty = !count($this->selections);

        if (!$refresh && !$isEmpty) {
            return $this->selections;
        }

        // Check the cache.
        $container = new Container('EasyAdmin');
        if (!$refresh && isset($container->selections)) {
            $this->selections = $container->selections;
            $isEmpty = !count($this->selections);
            if (!$isEmpty) {
                return $this->selections;
            }
        }

        $this->selections = [];
        $csv = $this->fileGetContents('https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/refs/heads/master/_data/omeka_s_selections.csv');
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
                        $dirs = explode(',', $row[$headers['Modules and themes']] ?? '');
                        $this->selections[$name] = array_values(array_filter(array_map(
                            fn ($v) => str_replace(' ', '', trim($v)),
                            $dirs
                        )));
                    }
                }
            }
        }

        $container->selections = $this->selections;
        $container
            ->setExpirationSeconds($this->expirationSeconds)
            ->setExpirationHops($this->expirationHops);

        return $this->selections;
    }

    /**
     * Check if the lists of addons are empty before init.
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
        $listAddons = $this->getAddons();

        $list = $type
            ? (isset($listAddons[$type]) ? [$type => $listAddons[$type]] : [])
            : $listAddons;
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
        $listAddons = $this->getAddons();
        return $listAddons && isset($listAddons[$type][$url])
            ? $listAddons[$type][$url]
            : [];
    }

    /**
     * Check if an addon is installed.
     *
     * @param array $addon
     */
    public function dirExists($addon): bool
    {
        $subDir = $this->data[$addon['type']]['destination'];
        $dirs = [OMEKA_PATH . $subDir];
        $composerDir = OMEKA_PATH . '/composer-addons' . $subDir;
        if (is_dir($composerDir)) {
            $dirs[] = $composerDir;
        }
        $dirLower = strtolower($addon['dir']);
        $baseLower = strtolower($addon['basename']);
        foreach ($dirs as $destination) {
            $existings = array_map(
                'strtolower',
                $this->listDirsInDir($destination)
            );
            if (in_array($dirLower, $existings)
                || in_array($baseLower, $existings)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an addon is managed by Composer (in composer-addons/).
     */
    public function isComposerManaged(array $addon): bool
    {
        $type = $addon['type'] ?? '';
        $dir = $addon['dir'] ?? '';
        if (!$type || !$dir) {
            return false;
        }

        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $composerPath = OMEKA_PATH . '/composer-addons/' . $subDir
            . '/' . $dir;

        if (!file_exists($composerPath)) {
            return false;
        }

        // Check if the local path is a symlink pointing to
        // composer-addons.
        $localPath = OMEKA_PATH . '/' . $subDir . '/' . $dir;
        if (is_link($localPath)) {
            $target = realpath($localPath);
            $composerReal = realpath($composerPath);
            if ($target && $composerReal
                && strpos($target, $composerReal) === 0
            ) {
                return true;
            }
        }

        // The addon exists only in composer-addons/.
        $destination = OMEKA_PATH . '/' . $subDir . '/' . $dir;
        return !file_exists($destination) || !is_dir($destination);
    }

    /**
     * Get the installed version from the addon ini file.
     */
    public function getInstalledVersion(array $addon): ?string
    {
        $type = $addon['type'] ?? '';
        $dir = $addon['dir'] ?? '';
        if (!$type || !$dir) {
            return null;
        }

        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $iniFile = in_array($type, ['module', 'omekamodule'])
            ? 'config/module.ini' : 'config/theme.ini';

        $path = OMEKA_PATH . '/' . $subDir . '/' . $dir
            . '/' . $iniFile;

        // Fallback to composer-addons.
        if (!file_exists($path)) {
            $path = OMEKA_PATH . '/composer-addons/' . $subDir
                . '/' . $dir . '/' . $iniFile;
        }

        if (!file_exists($path)) {
            return null;
        }

        $ini = parse_ini_file($path);
        return $ini['version'] ?? null;
    }

    /**
     * Enrich the addon list with local state information.
     *
     * Fills installed_version, installed, is_composer, and
     * update_available for each addon.
     */
    public function enrichWithLocalState(array &$addons): void
    {
        foreach ($addons as $type => &$addonsForType) {
            foreach ($addonsForType as $url => &$addon) {
                $addon['installed'] = $this->dirExists($addon);
                $addon['is_composer'] = $this->isComposerManaged(
                    $addon
                );
                $addon['installed_version'] = $addon['installed']
                    ? $this->getInstalledVersion($addon)
                    : null;
                $addon['update_available'] = $addon['installed']
                    && $addon['installed_version']
                    && $addon['version']
                    && version_compare(
                        $addon['installed_version'],
                        $addon['version'],
                        '<'
                    );

                // For modules, get the state from ModuleManager.
                if ($addon['installed']
                    && $this->moduleManager
                    && in_array($type, ['module', 'omekamodule'])
                ) {
                    $module = $this->moduleManager->getModule(
                        $addon['dir']
                    );
                    $addon['state'] = $module
                        ? $module->getState() : null;
                } else {
                    $addon['state'] = null;
                }
            }
        }
        unset($addon, $addonsForType);
    }

    /**
     * Update an addon: download new version with backup/rollback.
     *
     * @return bool True on success.
     */
    public function updateAddon(array $addon): bool
    {
        if ($this->isComposerManaged($addon)) {
            $this->messenger->addError(new PsrMessage(
                'The addon "{name}" is managed by Composer and cannot be updated here.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        $type = $addon['type'] ?? '';
        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $destination = OMEKA_PATH . '/' . $subDir;
        $addonDir = $destination . '/' . $addon['dir'];

        if (!is_writeable($destination)) {
            $this->messenger->addError(new PsrMessage(
                'The {type} directory is not writeable by the server.', // @translate
                ['type' => $subDir]
            ));
            return false;
        }

        if (!file_exists($addonDir)) {
            $this->messenger->addError(new PsrMessage(
                'The addon "{name}" is not installed.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        $tempDir = sys_get_temp_dir() . '/easyadmin_update_'
            . $addon['dir'] . '_' . bin2hex(random_bytes(8));
        @mkdir($tempDir, 0775, true);
        $extractDir = $tempDir . '/extract';
        @mkdir($extractDir, 0775, true);

        // Pick the latest compatible version for current Omeka S.
        $compatible = $this->pickCompatibleVersion($addon);
        if ($compatible) {
            $addon['version'] = $compatible['version'];
            $addon['zip'] = $compatible['download_url'] ?: $addon['zip'];
        } elseif (!empty($addon['versions'])) {
            $this->messenger->addError(new PsrMessage(
                'No version of "{name}" is compatible with this Omeka S.', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Download new version.
        $zip = $addon['zip'] ?? '';
        if (!$zip) {
            $this->messenger->addError(new PsrMessage(
                'No download URL available for "{name}".', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }
        $zipFile = $tempDir . '/' . basename($zip);
        $result = $this->downloadFile($zip, $zipFile);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'Unable to fetch the update for "{name}" from {url}. Check the server internet access and that "allow_url_fopen" is enabled.', // @translate
                ['name' => $addon['name'], 'url' => $zip]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Unzip into a fresh sub-directory to avoid any collision with the
        // downloaded zip file or stale entries.
        $result = $this->unzipFile($zipFile, $extractDir);
        @unlink($zipFile);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'An error occurred during the unzipping of "{name}".', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Backup current directory as a zip in files/backup/ so it does not
        // appear in the module/theme list.
        $backupPath = $this->backupAddonAsZip($addonDir, $addon);
        if (!$backupPath) {
            $this->messenger->addError(new PsrMessage(
                'Unable to backup the current version of "{name}".', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }
        // Remove the original directory to make room for the new version.
        $this->rmDir($addonDir);

        // Find the extracted directory inside temp (the zip top-level dir may
        // have any name, e.g. "Foo-1.0.1").
        $extractedPath = null;
        foreach (array_diff(scandir($extractDir) ?: [], ['.', '..']) as $entry) {
            if (is_dir($extractDir . '/' . $entry)) {
                $extractedPath = $extractDir . '/' . $entry;
                break;
            }
        }
        if (!$extractedPath) {
            @rename($backupDir, $addonDir);
            $this->messenger->addError(new PsrMessage(
                'The archive for "{name}" does not contain a directory.', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Move extracted dir to the addon location. Use rename first (fast,
        // same filesystem), fall back to recursive copy (cross-device).
        $moved = @rename($extractedPath, $addonDir);
        if (!$moved) {
            $moved = $this->copyDir($extractedPath, $addonDir);
        }

        $this->rmDir($tempDir);

        if (!$moved || !file_exists($addonDir)) {
            // Rollback: restore from backup zip.
            $this->restoreAddonFromZip($backupPath, $addonDir);
            $this->messenger->addError(new PsrMessage(
                'Failed to install the update for "{name}" (move from temp failed). The previous version has been restored.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        // Generate checksums for the new version.
        $this->generateChecksums($addon);

        $this->messenger->addSuccess(new PsrMessage(
            'The addon "{name}" was successfully updated. A backup is stored in "{backup}".', // @translate
            [
                'name' => $addon['name'],
                'backup' => 'files/backup/' . basename($backupPath),
            ]
        ));

        return true;
    }

    /**
     * Remove an addon: uninstall from DB and delete files.
     *
     * @return bool True on success.
     */
    public function removeAddon(array $addon): bool
    {
        if ($this->isComposerManaged($addon)) {
            $this->messenger->addError(new PsrMessage(
                'The addon "{name}" is managed by Composer and cannot be removed here.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        $type = $addon['type'] ?? '';
        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $destination = OMEKA_PATH . '/' . $subDir;
        $addonDir = $destination . '/' . $addon['dir'];

        if (!is_writeable($destination)) {
            $this->messenger->addError(new PsrMessage(
                'The {type} directory is not writeable by the server.', // @translate
                ['type' => $subDir]
            ));
            return false;
        }

        if (!file_exists($addonDir)) {
            $this->messenger->addError(new PsrMessage(
                'The addon "{name}" is not installed.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        // For modules: uninstall from DB via ModuleManager.
        if (in_array($type, ['module', 'omekamodule'])
            && $this->moduleManager
        ) {
            $module = $this->moduleManager->getModule(
                $addon['dir']
            );
            if ($module) {
                $state = $module->getState();
                try {
                    if ($state === ModuleManager::STATE_ACTIVE) {
                        $this->moduleManager->deactivate($module);
                    }
                    if (in_array($state, [
                        ModuleManager::STATE_ACTIVE,
                        ModuleManager::STATE_NOT_ACTIVE,
                    ])) {
                        $this->moduleManager->uninstall($module);
                    }
                } catch (Exception $e) {
                    $this->messenger->addError(new PsrMessage(
                        'Error during uninstall of "{name}": {error}', // @translate
                        [
                            'name' => $addon['name'],
                            'error' => $e->getMessage(),
                        ]
                    ));
                    return false;
                }
            }
        }

        // For themes: check no site uses it as active theme.
        if (in_array($type, ['theme', 'omekatheme'])) {
            $sites = $this->api->search('sites', [
                'limit' => 0,
            ])->getContent();
            foreach ($sites as $site) {
                $siteTheme = $site->theme();
                if ($siteTheme === $addon['dir']) {
                    $this->messenger->addError(new PsrMessage(
                        'The theme "{name}" is used by site "{site}" and cannot be removed.', // @translate
                        [
                            'name' => $addon['name'],
                            'site' => $site->title(),
                        ]
                    ));
                    return false;
                }
            }
        }

        // Backup before removal.
        $backupPath = $this->backupAddonAsZip(
            $addonDir, $addon
        );
        if (!$backupPath) {
            $this->messenger->addError(new PsrMessage(
                'Unable to backup "{name}" before removal.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        $result = $this->rmDir($addonDir);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'Unable to remove the directory of "{name}".', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        // Remove checksums file if exists.
        $checksumsDir = OMEKA_PATH
            . '/files/check/addon-checksums';
        $checksumsFile = $checksumsDir . '/'
            . $addon['dir'] . '.json';
        if (file_exists($checksumsFile)) {
            @unlink($checksumsFile);
        }

        $this->messenger->addSuccess(new PsrMessage(
            'The addon "{name}" was removed. A backup is stored in "{backup}".', // @translate
            [
                'name' => $addon['name'],
                'backup' => 'files/backup/' . basename($backupPath),
            ]
        ));

        return true;
    }

    /**
     * Generate SHA-256 checksums for an addon and store as JSON.
     */
    public function generateChecksums(array $addon): bool
    {
        $type = $addon['type'] ?? '';
        $dir = $addon['dir'] ?? '';
        if (!$type || !$dir) {
            return false;
        }

        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $addonDir = OMEKA_PATH . '/' . $subDir . '/' . $dir;

        if (!file_exists($addonDir) || !is_dir($addonDir)) {
            return false;
        }

        $checksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $addonDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = ltrim(
                    str_replace($addonDir, '', $file->getPathname()),
                    '/'
                );
                // Skip vendor/ and node_modules/.
                if (strpos($relativePath, 'vendor/') === 0
                    || strpos($relativePath, 'node_modules/') === 0
                ) {
                    continue;
                }
                $checksums[$relativePath] = hash_file(
                    'sha256',
                    $file->getPathname()
                );
            }
        }

        ksort($checksums);

        $checksumsDir = OMEKA_PATH
            . '/files/check/addon-checksums';
        if (!file_exists($checksumsDir)) {
            @mkdir($checksumsDir, 0775, true);
        }

        $version = $this->getInstalledVersion($addon);
        $data = [
            'addon' => $dir,
            'version' => $version,
            'generated' => date('c'),
            'algorithm' => 'sha256',
            'files' => $checksums,
        ];

        $result = file_put_contents(
            $checksumsDir . '/' . $dir . '.json',
            json_encode($data, JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES)
        );

        return $result !== false;
    }

    /**
     * Check integrity of an addon by comparing checksums.
     *
     * @param bool $fromSource Re-download zip to generate
     *   reference checksums.
     * @return array With keys status, modified, added, deleted.
     */
    public function checkIntegrity(
        array $addon,
        bool $fromSource = false
    ): array {
        $result = [
            'status' => 'unknown',
            'modified' => [],
            'added' => [],
            'deleted' => [],
        ];

        $type = $addon['type'] ?? '';
        $dir = $addon['dir'] ?? '';
        if (!$type || !$dir) {
            return $result;
        }

        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $addonDir = OMEKA_PATH . '/' . $subDir . '/' . $dir;

        if (!file_exists($addonDir)) {
            return $result;
        }

        if ($fromSource) {
            $referenceChecksums = $this
                ->generateChecksumsFromSource($addon);
        } else {
            $checksumsFile = OMEKA_PATH
                . '/files/check/addon-checksums/'
                . $dir . '.json';
            if (!file_exists($checksumsFile)) {
                return $result;
            }
            $json = json_decode(
                file_get_contents($checksumsFile),
                true
            );
            $referenceChecksums = $json['files'] ?? [];
        }

        if (!$referenceChecksums) {
            return $result;
        }

        // Compute current checksums.
        $currentChecksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $addonDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = ltrim(
                    str_replace(
                        $addonDir,
                        '',
                        $file->getPathname()
                    ),
                    '/'
                );
                if (strpos($relativePath, 'vendor/') === 0
                    || strpos(
                        $relativePath,
                        'node_modules/'
                    ) === 0
                ) {
                    continue;
                }
                $currentChecksums[$relativePath] = hash_file(
                    'sha256',
                    $file->getPathname()
                );
            }
        }

        // Compare.
        foreach ($referenceChecksums as $path => $hash) {
            if (!isset($currentChecksums[$path])) {
                $result['deleted'][] = $path;
            } elseif ($currentChecksums[$path] !== $hash) {
                $result['modified'][] = $path;
            }
        }
        foreach ($currentChecksums as $path => $hash) {
            if (!isset($referenceChecksums[$path])) {
                $result['added'][] = $path;
            }
        }

        $result['status'] = ($result['modified']
            || $result['added']
            || $result['deleted'])
            ? 'modified' : 'clean';

        return $result;
    }

    /**
     * Download the source zip and compute reference checksums.
     */
    protected function generateChecksumsFromSource(
        array $addon
    ): array {
        $tempDir = sys_get_temp_dir() . '/easyadmin_check_'
            . ($addon['dir'] ?? 'unknown') . '_' . bin2hex(random_bytes(8));
        @mkdir($tempDir, 0775, true);
        $extractDir = $tempDir . '/extract';
        @mkdir($extractDir, 0775, true);

        $zipFile = $tempDir . '/' . basename($addon['zip'] ?? '');
        if (!$this->downloadFile(
            $addon['zip'] ?? '',
            $zipFile
        )) {
            $this->rmDir($tempDir);
            return [];
        }

        if (!$this->unzipFile($zipFile, $extractDir)) {
            $this->rmDir($tempDir);
            return [];
        }
        @unlink($zipFile);

        // Find the extracted directory.
        $dirs = array_filter(
            array_diff(scandir($extractDir) ?: [], ['.', '..']),
            fn ($f) => is_dir($extractDir . '/' . $f)
        );
        $extractedDir = $extractDir . '/' . reset($dirs);
        if (!$extractedDir || !is_dir($extractedDir)) {
            $this->rmDir($tempDir);
            return [];
        }

        $checksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $extractedDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = ltrim(
                    str_replace(
                        $extractedDir,
                        '',
                        $file->getPathname()
                    ),
                    '/'
                );
                if (strpos($relativePath, 'vendor/') === 0
                    || strpos(
                        $relativePath,
                        'node_modules/'
                    ) === 0
                ) {
                    continue;
                }
                $checksums[$relativePath] = hash_file(
                    'sha256',
                    $file->getPathname()
                );
            }
        }

        $this->rmDir($tempDir);
        ksort($checksums);
        return $checksums;
    }

    protected function initAddons(bool $refresh = false): self
    {
        // Build the list of addons only once.
        if (!$refresh && !$this->isEmpty()) {
            return $this;
        }

        // Check the cache.
        $container = new Container('EasyAdmin');
        if (!$refresh && isset($container->addons)) {
            $this->addons = $container->addons;
            if (!$this->isEmpty()) {
                return $this;
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

        return $this;
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
        // Use Laminas Http first: works even when allow_url_fopen is off.
        // Generous timeout: some sources (GitHub raw) serve 300+ KB JSON files
        // and the Laminas Socket adapter forces HTTP/1.1.
        //
        // Try HTTP/2 (curl + nghttp2) when available: CURL_HTTP_VERSION_2TLS
        // negotiates h2 via TLS-ALPN and falls back to HTTP/1.1 transparently
        // if the server does not support HTTP/2.
        //
        // Proxy and SSL options live on the HttpClient itself (not the
        // adapter). The client passes its config to the adapter on every
        // send(), so swapping the adapter does not lose proxy/cert settings.
        $body = null;
        $canHttp2 = extension_loaded('curl')
            && defined('CURL_HTTP_VERSION_2TLS');
        $originalAdapter = null;

        try {
            $this->httpClient->reset();
            $this->httpClient->setUri(new HttpUri($url));
            $this->httpClient->setOptions([
                'timeout' => 30,
                'connecttimeout' => 5,
            ]);

            if ($canHttp2) {
                $currentAdapter = $this->httpClient->getAdapter();
                if ($currentAdapter instanceof CurlAdapter) {
                    // Already curl: just hint HTTP/2 via merged curloptions.
                    $currentAdapter->setOptions([
                        'curloptions' => [
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
                        ],
                    ]);
                } else {
                    // Replace the adapter for this single call, keep a ref
                    // to restore it afterwards (the HttpClient service is
                    // shared).
                    $originalAdapter = $currentAdapter;
                    $curl = new CurlAdapter();
                    $curl->setOptions([
                        'curloptions' => [
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
                        ],
                    ]);
                    $this->httpClient->setAdapter($curl);
                }
            }

            $response = $this->httpClient->send();
            if ($response->isOk()) {
                $body = $response->getBody();
            }
        } catch (\Exception $e) {
            $body = null;
        } finally {
            if ($originalAdapter !== null) {
                $this->httpClient->setAdapter($originalAdapter);
            }
        }

        // Fallback to the original adapter (HTTP/1.1) if curl was used and
        // failed for any non-protocol reason (timeout, broken pipe, etc.).
        if (empty($body) && $canHttp2 && $originalAdapter !== null) {
            try {
                $this->httpClient->reset();
                $this->httpClient->setUri(new HttpUri($url));
                $this->httpClient->setOptions([
                    'timeout' => 30,
                    'connecttimeout' => 5,
                ]);
                $response = $this->httpClient->send();
                if ($response->isOk()) {
                    $body = $response->getBody();
                }
            } catch (\Exception $e) {
                $body = null;
            }
        }

        // Last-resort fallback: file_get_contents when allow_url_fopen is on.
        if (empty($body) && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create([
                'http' => ['timeout' => 30, 'ignore_errors' => true],
                'https' => ['timeout' => 30, 'ignore_errors' => true],
            ]);
            $body = @file_get_contents($url, false, $context) ?: null;
        }

        if (empty($body)) {
            $this->messenger->addError(new PsrMessage(
                'Unable to fetch the url {url}.', // @translate
                ['url' => $url]
            ));
            return null;
        }

        return $body;
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
            $host = $data['host'] ?? 'github.com';
            $url = 'https://' . $host . '/' . $data['owner'] . '/' . $data['repo'];
            // Fallback when no download_url: use host-specific archive URL. The
            // url with the default branch may not include dependencies.
            $zip = $data['versions'][$version]['download_url']
                ?? $this->fallbackArchiveUrl($host, $url, $data['repo'], $version);

            // Build a sorted list of versions with their omeka compatibility
            // constraint (descending, latest first).
            $versions = [];
            foreach (($data['versions'] ?? []) as $v => $vData) {
                $versions[$v] = [
                    'version' => $v,
                    'omeka_version_constraint' => $vData['omeka_version_constraint'] ?? '',
                    'download_url' => $vData['download_url'] ?? '',
                ];
            }
            uksort($versions, 'version_compare');
            $versions = array_reverse($versions, true);

            $addon = [];
            $addon['type'] = in_array($type, ['module', 'omekamodule'], true) ? 'module' : 'theme';
            $addon['server'] = strpos($type, 'omeka') === 0 ? 'omeka.org' : 'github';
            $addon['name'] = $name;
            $addon['basename'] = $data['dirname'];
            $addon['dir'] = $data['dirname'];
            $addon['version'] = $data['latest_version'];
            $addon['versions'] = $versions;
            $addon['url'] = $url;
            $addon['zip'] = $zip;
            $addon['dependencies'] = [];

            $list[$url] = $addon;
        }

        return $list;
    }

    /**
     * Pick the latest version from $addon['versions'] compatible with the
     * current Omeka S. Returns null if no compatible version, or if the addon
     * has no versions metadata.
     */
    protected function pickCompatibleVersion(array $addon): ?array
    {
        if (empty($addon['versions']) || !is_array($addon['versions'])) {
            return null;
        }
        $omekaVersion = \Omeka\Module::VERSION;
        foreach ($addon['versions'] as $v => $vData) {
            $constraint = (string) ($vData['omeka_version_constraint'] ?? '');
            if (!mb_strlen($constraint)) {
                return ['version' => $v] + $vData;
            }
            try {
                if (\Composer\Semver\Semver::satisfies($omekaVersion, $constraint)) {
                    return ['version' => $v] + $vData;
                }
            } catch (\UnexpectedValueException $e) {
                if ($this->checkConstraintFallback($omekaVersion, $constraint) !== false) {
                    return ['version' => $v] + $vData;
                }
            }
        }
        return null;
    }

    /**
     * version_compare-based fallback for non-semver constraints. Returns null
     * when the constraint cannot be parsed.
     */
    protected function checkConstraintFallback(string $version, string $constraint): ?bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (preg_split('~\s*\|\|\s*~', $constraint) as $orPart) {
            $orPart = trim($orPart);
            if ($orPart === '') {
                continue;
            }
            if (preg_match('~^(?<a>\d[\d.]*)\s*-\s*(?<b>\d[\d.]*)$~', $orPart, $m)) {
                if (version_compare($version, $m['a'], '>=') && version_compare($version, $m['b'], '<=')) {
                    return true;
                }
                continue;
            }
            $clauses = preg_split('~[\s,]+~', $orPart);
            $allOk = true;
            foreach ($clauses as $clause) {
                if ($clause === '') {
                    continue;
                }
                if (!preg_match('~^(?<op>>=|<=|<>|!=|==|=|>|<|\^|~)?\s*(?<v>\d[\d.A-Za-z\-+]*)$~', $clause, $m)) {
                    return null;
                }
                $op = $m['op'] ?: '=';
                $v = $m['v'];
                if ($op === '^' || $op === '~') {
                    $parts = explode('.', $v);
                    $major = (int) ($parts[0] ?? 0);
                    $minor = (int) ($parts[1] ?? 0);
                    $upper = $op === '^' ? ($major + 1) . '.0.0' : $major . '.' . ($minor + 1) . '.0';
                    if (!version_compare($version, $v, '>=') || !version_compare($version, $upper, '<')) {
                        $allOk = false;
                        break;
                    }
                    continue;
                }
                $cmpOp = $op === '=' ? '==' : $op;
                if (!version_compare($version, $v, $cmpOp)) {
                    $allOk = false;
                    break;
                }
            }
            if ($allOk) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build an archive URL for the default branch when no release zip is
     * available. Handles github, gitlab, and a sensible default.
     */
    protected function fallbackArchiveUrl(string $host, string $url, string $repo, string $version = 'master'): string
    {
        $branch = $version ?: 'master';
        switch (strtolower($host)) {
            case 'github.com':
                return $url . '/archive/refs/heads/' . $branch . '.zip';
            case 'gitlab.com':
            default:
                if (strpos($host, 'gitlab') !== false) {
                    return $url . '/-/archive/' . $branch . '/' . $repo . '-' . $branch . '.zip';
                }
                return $url . '/archive/' . $branch . '.zip';
        }
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

        $addonDir = $destination . '/' . $addon['dir'];
        if (file_exists($addonDir)) {
            $this->messenger->addError(new PsrMessage(
                'The {type} "{name}" already exists. Use update instead.', // @translate
                ['type' => $type, 'name' => $addon['dir']]
            ));
            return false;
        }

        // Download into a temp directory to avoid polluting the modules/themes
        // dir during extraction.
        $tempDir = sys_get_temp_dir() . '/easyadmin_install_'
            . $addon['dir'] . '_' . bin2hex(random_bytes(8));
        @mkdir($tempDir, 0775, true);
        $extractDir = $tempDir . '/extract';
        @mkdir($extractDir, 0775, true);

        // Pick the latest compatible version for current Omeka S.
        $compatible = $this->pickCompatibleVersion($addon);
        if ($compatible) {
            $addon['version'] = $compatible['version'];
            $addon['zip'] = $compatible['download_url'] ?: $addon['zip'];
        } elseif (!empty($addon['versions'])) {
            $this->messenger->addError(new PsrMessage(
                'No version of "{name}" is compatible with this Omeka S.', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        $zipFile = $tempDir . '/' . basename($addon['zip']);
        $result = $this->downloadFile($addon['zip'], $zipFile);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'Unable to fetch the {type} "{name}". Check the server internet access and that "allow_url_fopen" is enabled.', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        $result = $this->unzipFile($zipFile, $extractDir);
        @unlink($zipFile);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'An error occurred during the unzipping of the {type} "{name}".', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Find the extracted directory and move it to the final destination
        // with the correct name.
        $extractedPath = null;
        foreach (array_diff(scandir($extractDir) ?: [], ['.', '..']) as $entry) {
            if (is_dir($extractDir . '/' . $entry)) {
                $extractedPath = $extractDir . '/' . $entry;
                break;
            }
        }
        if (!$extractedPath) {
            $this->messenger->addError(new PsrMessage(
                'The archive for "{name}" does not contain a directory.', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Move to final location (cross-device safe).
        $result = @rename($extractedPath, $addonDir);
        if (!$result) {
            $result = $this->copyDir($extractedPath, $addonDir);
        }
        $this->rmDir($tempDir);

        if (!$result || !file_exists($addonDir)) {
            $this->messenger->addError(new PsrMessage(
                'Failed to install the {type} "{name}".', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            return false;
        }

        // Check the special case of dependency Generic to avoid a fatal error.
        // This is used only for modules downloaded from omeka.org, since the
        // dependencies are not available here.
        // TODO Get the dependencies for the modules on omeka.org.
        if ($type === 'module') {
            $moduleFile = $destination . DIRECTORY_SEPARATOR . $addon['dir'] . DIRECTORY_SEPARATOR . 'Module.php';
            if (file_exists($moduleFile) && filesize($moduleFile)) {
                $modulePhp = file_get_contents($moduleFile);
                if (strpos($modulePhp, 'use Generic\AbstractModule;') !== false) {
                    /** @var \Omeka\Api\Representation\ModuleRepresentation $module */
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

        // Generate checksums for integrity checking.
        $this->generateChecksums($addon);

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
        // Only allow http/https to prevent local file reads via file://, php://, etc.
        $scheme = parse_url($source, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            return false;
        }

        // Restrict to trusted hosts to prevent SSRF.
        $host = strtolower((string) parse_url($source, PHP_URL_HOST));
        $trustedHosts = [
            'github.com',
            'gitlab.com',
            'omeka.org',
            'api.github.com',
            'codeload.github.com',
        ];
        $isTrusted = false;
        foreach ($trustedHosts as $trusted) {
            if ($host === $trusted || str_ends_with($host, '.' . $trusted)) {
                $isTrusted = true;
                break;
            }
        }
        if (!$isTrusted) {
            return false;
        }

        // Limit download size to 200 MB to prevent disk exhaustion.
        $maxSize = 200 * 1024 * 1024;

        $handle = @fopen($source, 'rb');
        if (empty($handle)) {
            return false;
        }

        $destHandle = @fopen($destination, 'wb');
        if (!$destHandle) {
            @fclose($handle);
            return false;
        }

        $written = 0;
        while (!feof($handle)) {
            $chunk = @fread($handle, 8192);
            if ($chunk === false) {
                break;
            }
            $written += strlen($chunk);
            if ($written > $maxSize) {
                @fclose($handle);
                @fclose($destHandle);
                @unlink($destination);
                return false;
            }
            fwrite($destHandle, $chunk);
        }

        @fclose($handle);
        @fclose($destHandle);

        return $written > 0;
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
                // Validate entries to prevent zip-slip (path traversal).
                $realDestination = realpath($destination) ?: $destination;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if ($entryName === false
                        || strpos($entryName, '..') !== false
                        || strpos($entryName, '/') === 0
                        || strpos($entryName, '\\') === 0
                        || preg_match('/^[a-zA-Z]:/', $entryName)
                    ) {
                        $zip->close();
                        return false;
                    }
                }
                // Suppress harmless "File exists" warnings emitted by some
                // archives (duplicate directory entries) without losing the
                // boolean return value.
                $result = @$zip->extractTo($destination);
                $zip->close();
            } else {
                /*
                $zipErrors = [
                    ZipArchive::ER_EXISTS => 'File already exists',
                    ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                    ZipArchive::ER_INVAL => 'Invalid argument',
                    ZipArchive::ER_MEMORY => 'Malloc failure',
                    ZipArchive::ER_NOENT => 'No such file',
                    ZipArchive::ER_NOZIP => 'Not a zip archive',
                    ZipArchive::ER_OPEN => 'Can’t open file',
                    ZipArchive::ER_READ => 'Read error',
                    ZipArchive::ER_SEEK => 'Seek error',
                ];
                $this->logger->err(
                    'Error when unzipping: {msg}', // @translate
                    ['msg' => $zipErrors[$result] ?? 'Other zip error']
                );
                */
                $result = false;
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
        $type = $addon['type'] ?? '';
        $isModule = in_array($type, ['module', 'omekamodule']);
        $destination = $isModule
            ? (OMEKA_PATH . '/modules')
            : (OMEKA_PATH . '/themes');
        $addonDir = $addon['dir'] ?? '';
        if (!$addonDir) {
            return false;
        }

        $path = $destination . '/' . $addonDir;
        if (is_dir($path)) {
            return true;
        }

        // Scan destination for a directory that looks like the
        // addon (covers version suffixes like "Foo-1.0.1",
        // branch suffixes like "Foo-master", and common
        // prefixes like "omeka-s-module-Foo-master").
        // A valid module dir contains Module.php; a valid
        // theme dir contains config/theme.ini.
        $marker = $isModule ? 'Module.php' : 'config/theme.ini';
        $dirLower = strtolower($addonDir);

        foreach (array_diff(scandir($destination) ?: [], ['.', '..']) as $entry) {
            $entryPath = $destination . '/' . $entry;
            if (!is_dir($entryPath) || $entry === $addonDir) {
                continue;
            }
            // Quick check: the entry name must contain the
            // addon dir name (case-insensitive).
            if (stripos($entry, $dirLower) === false
                && stripos($entry, str_replace('-', '', $dirLower)) === false
            ) {
                continue;
            }
            // Validate: must contain the expected marker file.
            if (!file_exists($entryPath . '/' . $marker)) {
                continue;
            }
            // For modules, verify the namespace matches the
            // expected dir name.
            if ($isModule) {
                $modulePhp = @file_get_contents(
                    $entryPath . '/Module.php'
                );
                if ($modulePhp
                    && !preg_match(
                        '/^namespace\s+' . preg_quote($addonDir, '/') . '\s*;/m',
                        $modulePhp
                    )
                ) {
                    continue;
                }
            }
            // Found: rename to the expected dir name.
            $renamed = @rename($entryPath, $path);
            if (!$renamed) {
                $renamed = $this->copyDir($entryPath, $path);
                if ($renamed) {
                    $this->rmDir($entryPath);
                }
            }
            return $renamed;
        }

        return false;
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
    /**
     * Recursively copy a directory (cross-device fallback for rename).
     */
    protected function copyDir(string $src, string $dst): bool
    {
        if (!is_dir($src)) {
            return false;
        }
        if (!@mkdir($dst, 0775, true) && !is_dir($dst)) {
            return false;
        }
        $files = array_diff(scandir($src) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (is_dir($srcPath)) {
                if (!$this->copyDir($srcPath, $dstPath)) {
                    return false;
                }
            } elseif (!@copy($srcPath, $dstPath)) {
                return false;
            }
        }
        return true;
    }

    protected function rmDir(string $dirPath): bool
    {
        if (!file_exists($dirPath)) {
            return true;
        }
        $real = realpath($dirPath);
        if ($real === false
            || $real === '/'
            || strpos($real, '/..') !== false
        ) {
            return false;
        }
        $dirPath = $real;
        $files = array_diff(scandir($dirPath) ?: [], ['.', '..']);
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

    /**
     * Backup an addon directory as a zip in files/backup/.
     *
     * @return string|null Path to the created zip, or null on failure.
     */
    protected function backupAddonAsZip(
        string $addonDir,
        array $addon
    ): ?string {
        $backupDir = OMEKA_PATH . '/files/backup';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0775, true);
        }
        if (!is_dir($backupDir) || !is_writeable($backupDir)) {
            return null;
        }

        $version = $this->getInstalledVersion($addon) ?: 'unknown';
        $zipName = ($addon['dir'] ?? 'addon')
            . '-' . $version
            . '-' . date('Ymd_His') . '.zip';
        $zipPath = $backupDir . '/' . $zipName;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return null;
        }

        $baseDir = basename($addonDir);
        $this->addDirToZip($zip, $addonDir, $baseDir);
        $zip->close();

        return file_exists($zipPath) ? $zipPath : null;
    }

    /**
     * Recursively add a directory to a ZipArchive.
     */
    protected function addDirToZip(
        \ZipArchive $zip,
        string $dir,
        string $zipDir
    ): void {
        $zip->addEmptyDir($zipDir);
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            $entryZipPath = $zipDir . '/' . $entry;
            if (is_dir($path)) {
                $this->addDirToZip($zip, $path, $entryZipPath);
            } else {
                $zip->addFile($path, $entryZipPath);
            }
        }
    }

    /**
     * Restore an addon from a backup zip.
     */
    protected function restoreAddonFromZip(
        string $zipPath,
        string $addonDir
    ): bool {
        if (!file_exists($zipPath)) {
            return false;
        }
        $destination = dirname($addonDir);
        return $this->unzipFile($zipPath, $destination);
    }
}

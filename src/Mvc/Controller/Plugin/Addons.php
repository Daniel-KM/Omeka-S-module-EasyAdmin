<?php declare(strict_types=1);

namespace EasyAdmin\Mvc\Controller\Plugin;

use Common\Stdlib\PsrMessage;
use Laminas\Http\Client as HttpClient;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Session\Container;
use Laminas\Uri\Http as HttpUri;

/**
 * List addons for Omeka.
 */
class Addons extends AbstractPlugin
{
    /**
     * @var \Laminas\Http\Client
     */
    protected $httpClient;

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
     * Expiration seconds.
     *
     * @var int
     */
    protected $expirationSeconds = 3600;

    /**
     * Expiration hops.
     *
     * @var int
     */
    protected $expirationHops = 10;

    /**
     * Cache for the list of addons.
     *
     * @var array
     */
    protected $addons = [];

    public function __construct(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Return the addon list.
     *
     * @return array
     */
    public function __invoke(bool $refresh = false): array
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
        $client = $this->httpClient;
        $client->reset();
        $client->setUri($uri);
        $response = $client->send();
        $response = $response->isOk() ? $response->getBody() : null;

        if (empty($response)) {
            $this->getController()->messenger()->addError(new PsrMessage(
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
            $addon['dir'] = $addonName;
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

        $addons = json_decode($json, true);
        if (!$addons) {
            return [];
        }

        foreach ($addons as $name => $data) {
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
}

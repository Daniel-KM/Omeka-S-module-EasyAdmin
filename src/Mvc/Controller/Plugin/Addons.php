<?php
namespace EasyInstall\Mvc\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;
use Zend\Session\Container;

/**
 * List addons for Omeka.
 */
class Addons extends AbstractPlugin
{
    /**
     * Source of data and destination of addons.
     *
     * @var array
     */
    protected $data = [
        'module' => [
            'source' => 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/docs/_data/omeka_s_modules.csv',
            'destination' => '/modules',
        ],
        'theme' => [
            'source' => 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/docs/_data/omeka_s_themes.csv',
            'destination' => '/themes',
        ],
    ];

    /**
     * Expiration seconds.
     *
     * @var int
     */
    protected $expirationSeconds = 86400;

    /**
     * Expiration hops.
     *
     * @var int
     */
    protected $expirationHops = 50;

    /**
     * Cache for the list of addons.
     *
     * @var string
     */
    protected $addons;

    /**
     * Return the addon list.
     *
     * @return string
     */
    public function __invoke()
    {
        // Build the list of addons only once.
        if (!$this->isEmpty()) {
            return $this->addons;
        }

        // Check the cache.
        $container = new Container('EasyInstall');
        if (isset($container->addons)) {
            $this->addons = $container->addons;
            if (!$this->isEmpty()) {
                return $this->addons;
            }
        }

        $addons = [];
        foreach ($this->types() as $addonType) {
            $addons[$addonType] = $this->listAddonsForType($addonType);
        }

        $this->addons = $addons;
        $this->cacheAddons();
        return $this->addons;
    }

    /**
     * Helper to save addons in the cache.
     */
    protected function cacheAddons()
    {
        $container = new Container('EasyInstall');
        $container->setExpirationSeconds($this->expirationSeconds);
        $container->setExpirationHops($this->expirationHops);
        $container->addons = $this->addons;
    }

    /**
     * Check if the lists of addons are empty.
     *
     * @return bool
     */
    public function isEmpty()
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
     *
     * @return array
     */
    public function types()
    {
        return array_keys($this->data);
    }

    /**
     * Get addon data.
     *
     * @param string $url
     * @param string $type
     * @return array
     */
    public function dataForUrl($url, $type)
    {
        return $this->addons && isset($this->addons[$type][$url])
            ? $this->addons[$type][$url]
            : [];
    }

    /**
     * Check if an addon is installed.
     *
     * @param array $addon
     * @return bool
     */
    public function dirExists($addon)
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
     * @return array
     */
    protected function listAddonsForType($type)
    {
        if (!isset($this->data[$type]['source'])) {
            return [];
        }
        $source = $this->data[$type]['source'];

        $content = $this->fileGetContents($source);
        if (empty($content)) {
            return [];
        }

        return $this->extractAddonList($content, $type);
    }

    /**
     * Helper to get content from an external url.
     *
     * @param string $url
     * @return string
     */
    protected function fileGetContents($url)
    {
        if (ini_get('allow_url_fopen') && ini_get('allow_url_include')) {
            return @file_get_contents($url);
        }

        $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        if (curl_errno($ch) || $response === false) {
            $this->getController()->messenger()->addError(
                sprintf('Unable to fetch the url %s.', $url) // @translate
                . ' ' . 'You should enable "allow_url_fopen" and "allow_url_include" in php.ini.' // @translate
                . ' ' . curl_error($ch));
            $response = null;
        }
        curl_close($ch);
        return $response;
    }

    /**
     * Helper to parse a csv file to get urls and names of addons.
     *
     * @param string $csv
     * @param string $type
     * @return array
     */
    protected function extractAddonList($csv, $type)
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
            $version = $row[$headers['Last Version']];
            $addonName = preg_replace('~[^A-Za-z0-9]~', '', $name);
            $server = strtolower(parse_url($url, PHP_URL_HOST));
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

            $addon = [];
            $addon['type'] = $type;
            $addon['name'] = $name;
            $addon['basename'] = basename($url);
            $addon['dir'] = $addonName;
            $addon['version'] = $version;
            $addon['zip'] = $zip;
            $addon['server'] = $server;

            $list[$url] = $addon;
        }

        return $list;
    }

    /**
     * List directories in a directory, not recursively.
     *
     * @param string $dir
     * @return array
     */
    protected function listDirsInDir($dir)
    {
        static $dirs;

        if (isset($dirs[$dir])) {
            return $dirs[$dir];
        }

        if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }

        $list = array_filter(array_diff(scandir($dir), ['.', '..']), function ($file) use ($dir) {
            return is_dir($dir . DIRECTORY_SEPARATOR . $file);
        });

        $dirs[$dir] = $list;
        return $dirs[$dir];
    }
}

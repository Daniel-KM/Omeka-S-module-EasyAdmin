<?php declare(strict_types=1);

namespace EasyAdmin\Stdlib;

/**
 * Check the permissions of the files of Omeka, of the modules and of the themes.
 *
 * An unreadable file breaks only the pages that use it (or all of them for a
 * language file), and it may work until the cache of php is reset, so it can
 * remain unnoticed after a copy with wrong permissions.
 */
class FilePermissionsChecker
{
    /**
     * Directories that are not used by the web server.
     */
    protected const SKIPPED = ['.git', 'node_modules'];

    /**
     * Get the directories of the code of Omeka, of the modules and of the
     * themes.
     *
     * @return string[]
     */
    public function codeDirectories(string $basePath): array
    {
        return array_merge(
            [$basePath . '/application'],
            glob($basePath . '/{modules,themes,composer-addons/modules,composer-addons/themes}/*', GLOB_BRACE | GLOB_ONLYDIR) ?: []
        );
    }

    /**
     * Get the directories where the web server writes.
     *
     * @return string[]
     */
    public function writableDirectories(string $basePath, string $filesPath): array
    {
        return array_merge(
            [$filesPath],
            glob($filesPath . '/*', GLOB_ONLYDIR) ?: [],
            array_filter([$basePath . '/logs', $basePath . '/application/data/doctrine-proxies'], 'is_dir')
        );
    }

    /**
     * List the unreadable files and the directories that cannot be traversed.
     *
     * @param string[] $directories
     * @return array Paths by checked directory.
     */
    public function unreadable(array $directories): array
    {
        $result = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $paths = [];
            $stack = [$directory];
            while ($stack) {
                $dir = array_pop($stack);
                $handle = is_readable($dir) && is_executable($dir) ? @opendir($dir) : false;
                if (!$handle) {
                    $paths[] = $dir;
                    continue;
                }
                while (($name = readdir($handle)) !== false) {
                    if ($name === '.' || $name === '..' || in_array($name, self::SKIPPED, true)) {
                        continue;
                    }
                    $path = $dir . '/' . $name;
                    // Do not follow the inner symbolic links to avoid loops.
                    if (is_dir($path) && !is_link($path)) {
                        $stack[] = $path;
                    } elseif (file_exists($path) && !is_readable($path)) {
                        $paths[] = $path;
                    }
                }
                closedir($handle);
            }
            if ($paths) {
                sort($paths);
                $result[$directory] = $paths;
            }
        }
        return $result;
    }

    /**
     * @param string[] $directories
     * @return string[]
     */
    public function unwritable(array $directories): array
    {
        return array_values(array_filter($directories, fn ($directory) => is_dir($directory) && !is_writable($directory)));
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Stdlib;

/**
 * List the language files that the web server cannot read.
 *
 * The translator throws an exception on an unreadable file, so a copy with
 * wrong permissions may break all the pages.
 */
class LanguageFilesChecker
{
    /**
     * Get the default directories of the language files of Omeka, of the
     * modules and of the themes.
     *
     * @return string[]
     */
    public function directories(string $basePath): array
    {
        return array_merge(
            [$basePath . '/application/language'],
            glob($basePath . '/{modules,themes,composer-addons/modules,composer-addons/themes}/*/language', GLOB_BRACE | GLOB_ONLYDIR) ?: []
        );
    }

    /**
     * @param string[] $directories
     * @return string[] Paths of the unreadable files and directories.
     */
    public function unreadable(array $directories): array
    {
        $result = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            if (!is_readable($directory) || !is_executable($directory)) {
                $result[] = $directory;
                continue;
            }
            foreach (glob($directory . '/*.{po,mo}', GLOB_BRACE) ?: [] as $file) {
                if (!is_readable($file)) {
                    $result[] = $file;
                }
            }
        }
        return $result;
    }
}

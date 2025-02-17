<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

trait TraitEasyDir
{
    /**
     * @param string $filename
     * @return string|null Null if no error.
     */
    protected function checkFilename(?string $filename): ?string
    {
        if (!$filename) {
            return 'Filename empty.'; // @translate
        }

        if (mb_strlen($filename) < 3 || mb_strlen($filename) > 200) {
            return 'Filename too much short or long.'; // @translate
        }

        $forbiddenCharacters = '/\\?<>:*%|"\'`&#;';
        if (mb_substr($filename, 0, 1) === '.'
            || mb_strpos($filename, '..') !== false
            || preg_match('~' . preg_quote($forbiddenCharacters, '~'). '~', $filename)
        ) {
            return 'Filename contains forbidden characters.'; // @translate;
        }

        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!mb_strlen($extension)) {
            return 'Filename has no extension.'; // @translate
        }

        return null;
    }

    /**
     * @param string $localPath
     * @return string|null Null if no error.
     */
    protected function checkLocalPath(?string $localPath): ?string
    {
        if (!$localPath) {
            return 'Local path is not configured.'; // @translate
        }
        $localPath = $this->checkDestinationDir($localPath);
        if (!$localPath) {
            return 'Local path is not writeable.'; // @translate
        }
        return null;
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir(string $dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
                $this->logger()->err(
                    'The directory "{path}" is not writeable.', // @translate
                    ['path' => $dirPath]
                );
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->logger()->err(
                'The directory "{path}" is not writeable: {error}.', // @translate
                ['path' => $dirPath, 'error' => error_get_last()['message']]
            );
            return null;
        }
        return $dirPath;
    }
}

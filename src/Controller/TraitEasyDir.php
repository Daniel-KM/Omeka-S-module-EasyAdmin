<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

trait TraitEasyDir
{
    protected function getAndCheckDirPath(?string $dirPath, ?string &$errorMessage = null): ?string
    {
        $dirPath = mb_strlen((string) $dirPath)
            ? $dirPath
            : $this->settings()->get('easyadmin_local_path');
        $check = $this->checkDirPath($dirPath, $errorMessage);
        return $check
            ? $dirPath
            : null;
    }

    protected function checkFile(?string $filepath, ?string &$errorMessage = null): bool
    {
        $dirPath = pathinfo($filepath, PATHINFO_DIRNAME);
        $filename = pathinfo($filepath, PATHINFO_BASENAME);

        $errorMessage = null;
        $check = $this->checkDirPath($dirPath, $errorMessage);
        if (!$check) {
            $this->messenger()->addError($errorMessage);
            return false;
        }

        $errorMessage = null;
        $isFilenameValid = $this->checkFilename($filename, $errorMessage);
        if (!$isFilenameValid) {
            $this->messenger()->addError($errorMessage);
            return false;
        }

        $filepath = rtrim($dirPath, '//') . '/' . $filename;
        $fileExists = file_exists($filepath);
        if (!$fileExists) {
            $this->messenger()->addError('The file does not exist.'); // @ŧranslate
            return false;
        }

        if (is_dir($filepath)) {
            $this->messenger()->addError('The file is a dir.'); // @translate
            return false;
        }

        return true;
    }

    protected function checkFilename(?string $filename, ?string &$errorMessage = null): bool
    {
        if (!mb_strlen((string) $filename)) {
            $errorMessage = 'Filename empty.'; // @translate
            return false;
        }

        // The file should have an extension, so minimum size is 3.
        if (mb_strlen($filename) < 3 || mb_strlen($filename) > 200) {
            $errorMessage = 'Filename too much short or long.'; // @translate
            return false;
        }

        $forbiddenCharacters = '/\\?!<>:*%|{}"`&$#;';
        if (mb_substr($filename, 0, 1) === '.'
            || mb_strpos($filename, '../') !== false
            || preg_match('~' . preg_quote($forbiddenCharacters, '~') . '~', $filename)
        ) {
            $errorMessage = 'Filename contains forbidden characters.'; // @translate;
            return false;
        }

        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!mb_strlen($extension)) {
            $errorMessage = 'Filename has no extension.'; // @translate
            return false;
        }

        return true;
    }

    protected function checkDirPath(?string $dirPath, ?string &$errorMessage = null): bool
    {
        if (!mb_strlen((string) $dirPath)) {
            $errorMessage = 'Local path is not configured.'; // @translate
            return false;
        }

        $dirPath = rtrim($dirPath, '/') . '/';

        if (empty($this->allowAnyPath)) {
            if ($dirPath === $this->basePath
                || mb_strpos($dirPath, $this->basePath . '/') !== 0
            ) {
                $errorMessage = 'Local path should be a sub-directory of /files.'; // @translate
                return false;
            }

            if (!$this->settings()->get('easyadmin_local_path_any_files')) {
                $standardDirectories = [
                    'asset',
                    'large',
                    'medium',
                    'original',
                    'square',
                ];
                foreach ($standardDirectories as $dir) {
                    if (mb_strpos($dirPath, $this->basePath . '/' . $dir . '/') === 0) {
                        $errorMessage = 'Local path cannot be a directory managed by Omeka and should be inside /files.'; // @translate
                        return false;
                    }
                }
            }
        }

        if ($dirPath === '/') {
            $errorMessage = 'Local path cannot be the root directory.'; // @translate
            return false;
        }

        $dirPath = $this->checkDestinationDir($dirPath);
        if (!$dirPath) {
            $errorMessage = 'Local path is not writeable.'; // @translate
            return false;
        }

        return true;
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path of the directory to check.
     * @return string|null The dirpath if valid, else null.
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

<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

trait TraitEasyDir
{
    protected function getAndCheckLocalPath(?string &$errorMessage = null): ?string
    {
        $localPath = $this->settings()->get('easyadmin_local_path');
        $result = $this->checkLocalPath($localPath, $errorMessage);
        return $result
            ? $localPath
            : null;
    }

    protected function checkFile(?string $filename, ?string &$errorMessage = null): bool
    {
        $errorMessage = null;
        $localPath = $this->getAndCheckLocalPath($errorMessage);
        if (!$localPath) {
            $this->messenger()->addError($errorMessage);
            return false;
        }

        $errorMessage = null;
        $isFilenameValid = $this->checkFilename($filename, $errorMessage);
        if (!$isFilenameValid) {
            $this->messenger()->addError($errorMessage);
            return false;
        }

        $filepath = rtrim($localPath, '//') . '/' . $filename;
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
        if (!$filename) {
            $errorMessage = 'Filename empty.'; // @translate
            return false;
        }

        if (mb_strlen($filename) < 3 || mb_strlen($filename) > 200) {
            $errorMessage = 'Filename too much short or long.'; // @translate
            return false;
        }

        $forbiddenCharacters = '/\\?!<>:*%|{}"`&$#;';
        if (mb_substr($filename, 0, 1) === '.'
            || mb_strpos($filename, '../') !== false
            || preg_match('~' . preg_quote($forbiddenCharacters, '~'). '~', $filename)
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

    protected function checkLocalPath(?string $localPath, ?string &$errorMessage = null): bool
    {
        if (!$localPath) {
            $errorMessage = 'Local path is not configured.'; // @translate
            return false;
        }

        $localPathDir = rtrim($localPath, '/') . '/';

        if (empty($this->allowAnyPath)) {
            if ($localPathDir === $this->basePath
                || mb_strpos($localPathDir, $this->basePath . '/') !== 0
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
                    if (mb_strpos($localPathDir, $this->basePath . '/' . $dir . '/') === 0) {
                        $errorMessage = 'Local path cannot be a directory managed by Omeka and should be inside /files.'; // @translate
                        return false;
                    }
                }
            }
        }

        if ($localPathDir === '/') {
            $errorMessage = 'Local path cannot be the root directory.'; // @translate
            return false;
        }

        $localPath = $this->checkDestinationDir($localPath);
        if (!$localPath) {
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

<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

trait TraitEasyDir
{
    /**
     * Directories managed by Omeka that should never be modified.
     *
     * These directories contain original files and derivatives and can have
     * 100,000+ files. They are read-only: browsing allowed, but no upload/delete.
     */
    protected const PROTECTED_DIRECTORIES = [
        'asset',
        'large',
        'medium',
        'original',
        'square',
    ];

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

    /**
     * Check if a directory is protected (read-only, managed by Omeka).
     *
     * Protected directories include files/original, files/asset, and derivative
     * directories (large, medium, square). These can contain 100k+ files.
     */
    protected function isProtectedDirectory(?string $dirPath): bool
    {
        if (!mb_strlen((string) $dirPath)) {
            return false;
        }

        $dirPath = rtrim($dirPath, '/') . '/';
        $basePath = rtrim($this->basePath, '/') . '/';

        foreach (self::PROTECTED_DIRECTORIES as $dir) {
            if (mb_strpos($dirPath, $basePath . $dir . '/') === 0
                || $dirPath === $basePath . $dir . '/'
            ) {
                return true;
            }
        }

        return false;
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

    /**
     * Check if a directory path is valid for browsing.
     *
     * Protected directories (original, asset, derivatives) are allowed for
     * browsing but are read-only.
     *
     * @param bool $forWriting If true, protected directories will be rejected.
     */
    protected function checkDirPath(?string $dirPath, ?string &$errorMessage = null, bool $forWriting = false): bool
    {
        if (!mb_strlen((string) $dirPath)) {
            $errorMessage = 'Local path is not configured.'; // @translate
            return false;
        }

        $dirPath = rtrim($dirPath, '/') . '/';

        if ($dirPath === '/') {
            $errorMessage = 'Local path cannot be the root directory.'; // @translate
            return false;
        }

        if (empty($this->allowAnyPath)) {
            $basePath = rtrim($this->basePath, '/') . '/';
            if ($dirPath === $basePath
                || mb_strpos($dirPath, $basePath) !== 0
            ) {
                $errorMessage = 'Local path should be a sub-directory of /files.'; // @translate
                return false;
            }
        }

        // Check if it's a protected directory.
        $isProtected = $this->isProtectedDirectory($dirPath);

        // Protected directories cannot be written to.
        if ($forWriting && $isProtected) {
            $errorMessage = 'This directory is managed by Omeka and is read-only.'; // @translate
            return false;
        }

        // For protected directories, just check if readable (no write check).
        if ($isProtected) {
            if (!file_exists($dirPath) || !is_dir($dirPath) || !is_readable($dirPath)) {
                $errorMessage = 'Local path is not readable.'; // @translate
                return false;
            }
            return true;
        }

        // For non-protected directories, check writability.
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

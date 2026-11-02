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
    protected static $protectedDirectories = [
        'asset',
        'large',
        'medium',
        'original',
        'square',
    ];

    protected function getAndCheckDirPath(?string $dirPath, ?string &$errorMessage = null, bool $forWriting = false): ?string
    {
        $dirPath = mb_strlen((string) $dirPath)
            ? $dirPath
            : $this->settings()->get('easyadmin_local_path');
        $check = $this->checkDirPath($dirPath, $errorMessage, $forWriting);
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

        foreach (self::$protectedDirectories as $dir) {
            if (mb_strpos($dirPath, $basePath . $dir . '/') === 0
                || $dirPath === $basePath . $dir . '/'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the base path for user data directories.
     */
    protected function getUserDataBasePath(): string
    {
        return rtrim($this->basePath, '/') . '/userdata';
    }

    /**
     * Get the directory path for a specific user.
     */
    protected function getUserDirPath(int $userId): string
    {
        return $this->getUserDataBasePath() . '/' . $userId;
    }

    /**
     * Check if a path is within the userdata directory.
     */
    protected function isUserDataDirectory(string $dirPath): bool
    {
        $dirPath = rtrim($dirPath, '/') . '/';
        $userDataBase = $this->getUserDataBasePath() . '/';
        return mb_strpos($dirPath, $userDataBase) === 0;
    }

    /**
     * Extract user id from a userdata directory path.
     *
     * @return int|null The user id, or null if the path is not a valid userdata path.
     */
    protected function getUserIdFromPath(string $dirPath): ?int
    {
        $dirPath = rtrim($dirPath, '/');
        $userDataBase = $this->getUserDataBasePath();
        if (mb_strpos($dirPath, $userDataBase . '/') !== 0) {
            return null;
        }
        $relative = mb_substr($dirPath, mb_strlen($userDataBase) + 1);
        // The user id is the first (or only) path segment.
        $parts = explode('/', $relative, 2);
        $userId = $parts[0];
        return ctype_digit($userId) ? (int) $userId : null;
    }

    /**
     * Ensure the .htaccess file exists in the userdata base directory.
     *
     * Denies direct HTTP access to user directories. PHP filesystem access is
     * not affected, so sideload imports still work.
     */
    protected function ensureUserDataHtaccess(): void
    {
        $this->ensureDenyHtaccess($this->getUserDataBasePath());
    }

    /**
     * Ensure a "deny all" .htaccess exists in a private base directory.
     *
     * Private directories (backup, userdata) hold sensitive files that must
     * only be downloaded through an authenticated controller action. PHP
     * filesystem access is not affected, so backups and sideload imports still
     * work.
     */
    protected function ensureDenyHtaccess(string $dirBasePath): void
    {
        $this->plugin('checkDestinationDir')($dirBasePath, true);
    }

    /**
     * Stream a file from a private directory as an authenticated download.
     *
     * Confines the file to the allowed base directory (defense against path
     * traversal), sets the content type from the extension and streams it with
     * the SendFile plugin (supports http range requests for resumable
     * downloads). Access control (who may download) is the caller's
     * responsibility.
     *
     * @return \Laminas\Stdlib\ResponseInterface|null Null when the file is
     * outside the allowed directory or cannot be sent.
     */
    protected function sendPrivateFile(string $filepath, string $allowedBaseDir)
    {
        $safeFilename = basename($filepath);
        $realBaseDir = realpath($allowedBaseDir);
        $realFilepath = realpath($filepath);
        if ($realBaseDir === false || $realFilepath === false
            || strpos($realFilepath, $realBaseDir . DIRECTORY_SEPARATOR) !== 0
        ) {
            return null;
        }

        $contentTypes = [
            'sql' => 'application/sql',
            'gz' => 'application/gzip',
            'zip' => 'application/zip',
            'tar' => 'application/x-tar',
            'bz2' => 'application/x-bzip2',
        ];
        $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));

        return $this->sendFile($filepath, [
            'content_type' => $contentTypes[$extension] ?? null,
            'filename' => $safeFilename,
            'disposition_mode' => 'attachment',
            'cache' => false,
        ]) ?: null;
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

        $filepath = rtrim($dirPath, '/') . '/' . $filename;
        $fileExists = file_exists($filepath);
        if (!$fileExists) {
            $this->messenger()->addError('The file does not exist.'); // @translate
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

        // Resolve symlinks and ".." to prevent path traversal.
        $realDirPath = realpath(rtrim($dirPath, '/'));
        if (!$realDirPath) {
            // Directory does not exist yet; resolve parent.
            $parentDir = realpath(dirname(rtrim($dirPath, '/')));
            if (!$parentDir) {
                $errorMessage = 'Local path is not valid.'; // @translate
                return false;
            }
            $realDirPath = $parentDir . '/' . basename(rtrim($dirPath, '/'));
        }
        $realDirPath = rtrim($realDirPath, '/') . '/';

        if (empty($this->allowAnyPath)) {
            $basePath = rtrim(realpath($this->basePath) ?: $this->basePath, '/') . '/';
            if ($realDirPath === $basePath
                || mb_strpos($realDirPath, $basePath) !== 0
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
        return $this->plugin('checkDestinationDir')($dirPath);
    }
}

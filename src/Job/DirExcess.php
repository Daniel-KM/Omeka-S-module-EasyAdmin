<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DirExcess extends AbstractCheck
{
    public function perform(): void
    {
        parent::perform();

        $process = $this->getArg('process');

        $this->removeEmptyDirs();

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function removeEmptyDirs(): bool
    {
        $result = $this->removeEmptyDirsForType('original');
        if (!$result) {
            return false;
        }

        $types = array_keys($this->config['thumbnails']['types']);
        // Manage module Image Server.
        $types[] = 'tile';
        foreach ($types as $type) {
            $result = $this->removeEmptyDirsForType($type);
            if (!$result) {
                return false;
            }
        }

        return true;
    }

    protected function removeEmptyDirsForType($type): bool
    {
        $path = $this->basePath . '/' . $type;
        if (!file_exists($path)) {
            return true;
        }
        $this->logger->notice(
            'Processing type "{type}".', // @translate
            ['type' => $type]
        );
        $this->removeEmptySubFolders($path, true);
        return true;
    }

    /**
     * Remove empty sub-folders recursively.
     *
     * @see https://stackoverflow.com/questions/1833518/remove-empty-subfolders-with-php
     *
     * @param string $path
     * @param bool $root
     * @return bool
     */
    protected function removeEmptySubFolders($path, $root = false): bool
    {
        $empty = true;
        foreach (glob($path . DIRECTORY_SEPARATOR . '{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) as $file) {
            $empty &= is_dir($file) && $this->removeEmptySubFolders($file);
        }
        return $empty && !$root && rmdir($path);
    }
}

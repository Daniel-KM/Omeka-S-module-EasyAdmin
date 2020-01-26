<?php
namespace BulkCheck\Job;

class DirExcess extends AbstractCheck
{
    public function perform()
    {
        parent::perform();

        $process = $this->getArg('process');

        $this->removeEmptyDirs();

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function removeEmptyDirs()
    {
        $result = $this->removeEmptyDirsForType('original');
        if (!$result) {
            return false;
        }

        foreach (array_keys($this->config['thumbnails']['types']) as $type) {
            $result = $this->removeEmptyDirsForType($type);
            if (!$result) {
                return false;
            }
        }

        return true;
    }

    protected function removeEmptyDirsForType($type)
    {
        $path = $this->basePath . '/' . $type;
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
    protected function removeEmptySubFolders($path, $root = false)
    {
        $empty = true;
        foreach (glob($path . DIRECTORY_SEPARATOR . '{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) as $file) {
            $empty &= is_dir($file) && $this->removeEmptySubFolders($file);
        }
        return $empty && !$root && rmdir($path);
    }
}

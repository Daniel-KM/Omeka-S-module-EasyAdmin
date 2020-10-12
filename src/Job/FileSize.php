<?php declare(strict_types=1);
namespace BulkCheck\Job;

class FileSize extends AbstractCheckFile
{
    public function perform(): void
    {
        parent::perform();

        $process = $this->getArg('process');

        $this->checkFilesize($process === 'filesize_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    /**
     * Check the size of the files.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkFilesize($fix = false)
    {
        return $this->checkFileData('size', $fix);
    }
}

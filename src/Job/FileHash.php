<?php
namespace BulkCheck\Job;

class FileHash extends AbstractCheckFile
{
    public function perform()
    {
        parent::perform();

        $process = $this->getArg('process');

        $this->checkFilehash($process === 'filehash_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    /**
     * Check the hash of the files.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkFilehash($fix = false)
    {
        return $this->checkFileData('sha256', $fix);
    }
}

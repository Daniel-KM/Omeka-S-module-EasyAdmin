<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileHash extends AbstractCheckFile
{
    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate,
        'exists' => 'Exists', // @translate
        'sha256' => 'Database hash', // @translate
        'real_sha256' => 'Real hash', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->checkFilehash($process === 'files_hash_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
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

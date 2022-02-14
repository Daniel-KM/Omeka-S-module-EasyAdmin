<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileSize extends AbstractCheckFile
{
    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate
        'exists' => 'Exists', // @translate
        'size' => 'Database size', // @translate
        'real_size' => 'Real size', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->checkFilesize($process === 'files_size_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
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

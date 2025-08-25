<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileStorage extends AbstractCheckFile
{
    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate,
        'exists' => 'Exists', // @translate
        'storage_id' => 'Storage name', // @translate
        'real_storage_id' => 'New random storage name', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->checkFileStorageId($process === 'files_storage_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    /**
     * Check the storage id of the files.
     */
    protected function checkFileStorageId(bool $fix = false): bool
    {
        return $this->checkFileData('storage_id', $fix);
    }
}

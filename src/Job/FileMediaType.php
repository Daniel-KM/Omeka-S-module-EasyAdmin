<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileMediaType extends AbstractCheckFile
{
    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate,
        'exists' => 'Exists', // @translate
        'media_type' => 'Database media-type', // @translate
        'real_media_type' => 'Real media type', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->checkFileMediaType($process === 'files_media_type_fix');

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
    protected function checkFileMediaType($fix = false)
    {
        return $this->checkFileData('media_type', $fix);
    }
}

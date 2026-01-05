<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileMediaType extends AbstractCheckFile
{
    protected $checkColumn = 'media_type';

    protected $fixProcessName = 'files_media_type_fix';

    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate
        'exists' => 'Exists', // @translate
        'media_type' => 'Database media-type', // @translate
        'real_media_type' => 'Real media type', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        $this->performFileDataCheck();
    }
}

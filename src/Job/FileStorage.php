<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileStorage extends AbstractCheckFile
{
    protected $checkColumn = 'storage_id';

    protected $fixProcessName = 'files_storage_fix';

    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate
        'exists' => 'Exists', // @translate
        'storage_id' => 'Storage name', // @translate
        'real_storage_id' => 'New random storage name', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        $this->performFileDataCheck();
    }
}

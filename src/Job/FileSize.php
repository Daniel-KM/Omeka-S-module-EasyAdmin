<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileSize extends AbstractCheckFile
{
    protected $checkColumn = 'size';

    protected $fixProcessName = 'files_size_fix';

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
        $this->performFileDataCheck();
    }
}

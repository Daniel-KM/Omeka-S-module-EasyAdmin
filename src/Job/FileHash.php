<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileHash extends AbstractCheckFile
{
    protected $checkColumn = 'sha256';

    protected $fixProcessName = 'files_hash_fix';

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
        $this->performFileDataCheck();
    }
}

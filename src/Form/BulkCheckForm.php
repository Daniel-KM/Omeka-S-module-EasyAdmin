<?php
namespace BulkCheck\Form;

use Zend\Form\Element;
use Zend\Form\Form;

class BulkCheckForm extends Form
{
    public function init()
    {
        $this
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Processors', // @translate
                    'value_options' => [
                        'files_excess_check' => 'List files that are present in "/files/", but not in database', // @translate
                        'files_excess_move' => 'Move files that are present in "/files/", but not in database, into /files/check/', // @translate
                        'files_missing' => 'List files that are present in database, not in "/files/"', // @translate
                        'dirs_excess' => 'Remove empty directories in "/files/" (for module Archive Repertory)', // @translate
                        'filesize_check' => 'Check missing file sizes in database (not managed during upgrade to Omeka 1.2.0)', // @translate
                        'filesize_fix' => 'Fix all file sizes in database (for example after hard import)', // @translate
                        'filehash_check' => 'Check sha256 hashes of files', // @translate
                        'filehash_fix' => 'Fix wrong sha256 of files', // @translate
                        'media_position_check' => 'Check positions of media (start from 1, without missing number)', // @translate
                        'media_position_fix' => 'Fix wrong positions of media ', // @translate
                        'db_job_check' => 'Check dead jobs (living in database, but non-existent in system)', // @translate
                        'db_job_clean' => 'Set status "stopped" for jobs that never started, and "error" for the jobs that never ended.', // @translate
                        'db_job_clean_all' => 'Fix status as above for all jobs (when check cannot be done after a reboot).', // @translate
                        'db_session_check' => 'Check the size of the database table of sessions', // @translate
                        'db_session_clean' => 'Remove old sessions (more than 100 days)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'process',
                ],
            ])
        ;

        // Fix the formatting issue of the label in Omeka.
        $this
            ->get('process')->setLabelAttributes(['style' => 'display: inline-block']);

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'process',
                'required' => false,
            ])
        ;
    }
}

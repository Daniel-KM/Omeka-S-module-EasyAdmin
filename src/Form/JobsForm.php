<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Doctrine\DBAL\Connection;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class JobsForm extends Form
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function init(): void
    {
        $this
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Processors', // @translate
                    'value_options' => [
                        'files_excess_check' => 'List files that are present in "/files/", but not in database', // @translate
                        'files_excess_move' => 'Move files that are present in "/files/", but not in database, into "/files/check/"', // @translate
                        'files_missing_check' => 'List files that are present in database, not in "/files/" (original only)', // @translate
                        'files_missing_check_full' => 'List files that are present in database, not in "/files/" (include derivatives)', // @translate
                        'files_missing_fix' => 'Copy missing original files from the source directory below (recover a disaster)', // @translate
                        'files_missing_fix_db' => 'Remove items with one file that is missing (WARNING: export your items first)', // @translate
                        'files_derivative' => 'Rebuild derivative images (thumbnails) with options below', // @translate
                        'files_media_no_original' => 'Check media rendered as "file", but without original', // @translate
                        'files_media_no_original_fix' => 'Remove media rendered as "file", but without original (WARNING: export your media first)', // @translate
                        'dirs_excess' => 'Remove empty directories in "/files/" (for module Archive Repertory)', // @translate
                        'files_size_check' => 'Check missing file sizes in database (not managed during upgrade to Omeka 1.2.0)', // @translate
                        'files_size_fix' => 'Fix all file sizes in database (for example after hard import)', // @translate
                        'files_hash_check' => 'Check sha256 hashes of files', // @translate
                        'files_hash_fix' => 'Fix wrong sha256 of files', // @translate
                        'files_dimension_check' => 'Check files dimensions (modules IIIF Server / Image Server)', // @translate
                        'files_dimension_fix' => 'Fix files dimensions (modules IIIF Server / Image Server)', // @translate
                        'media_position_check' => 'Check positions of media (start from 1, without missing number)', // @translate
                        'media_position_fix' => 'Fix wrong positions of media', // @translate
                        'item_no_value' => 'Check items without value (media values are not checked)', // @translate
                        'item_no_value_fix' => 'Remove items without value (files are moved into "/files/check/")', // @translate
                        'db_utf8_encode_check' => 'Check if all values are utf-8 encoded (Windows issues like "Ã©" for "é", options below)', // @translate
                        'db_utf8_encode_fix' => 'Fix utf-8 encoding issues (options below)', // @translate
                        'db_resource_title_check' => 'Check resource titles (for example after hard import)', // @translate
                        'db_resource_title_fix' => 'Update resource titles', // @translate
                        'db_job_check' => 'Check dead jobs (living in database, but non-existent in system)', // @translate
                        'db_job_fix' => 'Set status "stopped" for jobs that never started, and "error" for the jobs that never ended', // @translate
                        'db_job_fix_all' => 'Fix status as above for all jobs (when check cannot be done after a reboot)', // @translate
                        'db_session_check' => 'Check the size of the table of sessions in database', // @translate
                        'db_session_clean' => 'Remove old sessions (specify age below)', // @translate
                        'db_log_check' => 'Check the size of the table of logs in database(module Log)', // @translate
                        'db_log_clean' => 'Remove old logs (options below)', // @translate
                        'db_fulltext_index' => 'Index full-text search (core job)', // @translate
                        'db_statistics_index' => 'Index statistics (module Statistics, needed only after direct import)', // @translate
                        'db_thesaurus_index' => 'Index thesaurus (module Thesaurus)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'process',
                    'required' => true,
                ],
            ]);

        $this
            ->add([
                'type' => Fieldset::class,
                'name' => 'files_missing',
                'options' => [
                    'label' => 'Options for fix missing files', // @translate
                ],
            ]);
        $this->get('files_missing')
            ->add([
                'name' => 'source_dir',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Source for restoration', // @translate
                ],
                'attributes' => [
                    'id' => 'source_dir',
                    'placeholder' => '/server/path/to/my/source/directory', // @translate
                ],
            ])
            ->add([
                'name' => 'extensions',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Limit to extensions', // @translate
                ],
                'attributes' => [
                    'id' => 'extensions',
                    'placeholder' => 'pdf, jpeg', // @translate
                ],
            ])
        ;

        $this
            ->add([
                'type' => Fieldset::class,
                'name' => 'files_derivative',
                'options' => [
                    'label' => 'Options to rebuild derivative files (thumbnails)', // @translate
                ],
            ]);
        $this->get('files_derivative')
            ->add([
                'name' => 'item_sets',
                'type' => OmekaElement\ItemSetSelect::class,
                'options' => [
                    'label' => 'Item sets', // @translate
                ],
                'attributes' => [
                    'id' => 'item_sets',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'required' => false,
                    'data-placeholder' => 'Select one or more item sets…', // @translate
                ],
            ])
            ->add([
                'name' => 'ingesters',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Ingesters to process', // @translate
                    'empty_option' => 'All ingesters', // @translate
                    'value_options' => $this->listIngesters(),
                ],
                'attributes' => [
                    'id' => 'ingesters',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select ingesters to process', // @ translate
                    'data-placeholder' => 'Select ingesters to process', // @ translate
                ],
            ])
            ->add([
                'name' => 'renderers',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Renderers to process', // @translate
                    'empty_option' => 'All renderers', // @translate
                    'value_options' => $this->listRenderers(),
                ],
                'attributes' => [
                    'id' => 'renderers',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select renderers to process', // @ translate
                    'data-placeholder' => 'Select renderers to process', // @ translate
                ],
            ])
            ->add([
                'name' => 'media_types',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Media types to process', // @translate
                    'empty_option' => 'All media types', // @translate
                    'value_options' => $this->listMediaTypes(),
                ],
                'attributes' => [
                    'id' => 'media_types',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select media types to process', // @ translate
                    'data-placeholder' => 'Select media types to process', // @ translate
                ],
            ])
            ->add([
                'name' => 'media_ids',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Media ids', // @translate
                ],
                'attributes' => [
                    'id' => 'media_ids',
                    'placeholder' => '2-6 8 38-52 80-', // @ translate
                ],
            ])
            ->add([
                'name' => 'original_without_thumbnails',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Only originals without thumbnails', // @translate
                ],
                'attributes' => [
                    'id' => 'original_without_thumbnails',
                ],
            ])
        ;

        $this
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_utf8_encode',
                'options' => [
                    'label' => 'Options for utf-8 encoding (experimental: do a backup first)', // @translate
                ],
            ]);
        $this->get('db_utf8_encode')
            ->add([
                'name' => 'type_resources',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Types of resources to process', // @translate
                    'value_options' => [
                        'all' => 'All', // @translate
                        'resource_title' => 'Resources titles', // @translate
                        'value' => 'Resources values', // @translate
                        'site_title' => 'Site title', // @translate
                        'site_summary' => 'Site summary', // @translate
                        'page_title' => 'Pages titles', // @translate
                        'page_block' => 'Pages blocks', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'type_resources',
                ],
            ]);

        $this
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_session',
                'options' => [
                    'label' => 'Options to remove sessions', // @translate
                ],
            ]);
        $this->get('db_session')
            ->add([
                'name' => 'days',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Older than this number of days', // @translate
                ],
                'attributes' => [
                    'id' => 'days',
                ],
            ]);

        $this
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_log',
                'options' => [
                    'label' => 'Options to remove logs (module Log)', // @translate
                ],
            ]);
        $this->get('db_log')
            ->add([
                'name' => 'days',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Older than this number of days', // @translate
                ],
                'attributes' => [
                    'id' => 'days',
                ],
            ])
            ->add([
                'name' => 'severity',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Maximum severity', // @translate
                    'value_options' => [
                        '0' => 'Emergency', // @translate
                        '1' => 'Alert', // @translate
                        '2' => 'Critical', // @translate
                        '3' => 'Error', // @translate
                        '4' => 'Warning', // @translate
                        '5' => 'Notice', // @translate
                        '6' => 'Info', // @translate
                        '7' => 'Debug', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'severity',
                ],
            ]);

        // Fix the formatting issue of the label in Omeka.
        $this
            ->get('process')->setLabelAttributes(['style' => 'display: inline-block']);

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'process',
                'required' => true,
            ]);
        $inputFilter->get('files_derivative')
            ->add([
                'name' => 'item_sets',
                'required' => false,
            ])
            ->add([
                'name' => 'ingesters',
                'required' => false,
            ])
            ->add([
                'name' => 'renderers',
                'required' => false,
            ])
            ->add([
                'name' => 'media_types',
                'required' => false,
            ]);
        $inputFilter->get('db_utf8_encode')
            ->add([
                'name' => 'type_resources',
                'required' => false,
            ]);
        $inputFilter->get('db_session')
            ->add([
                'name' => 'days',
                'required' => false,
            ]);
        $inputFilter->get('db_log')
            ->add([
                'name' => 'days',
                'required' => false,
            ]);
    }

    /**
     * @return array
     */
    protected function listIngesters()
    {
        $sql = 'SELECT DISTINCT(ingester) FROM media ORDER BY ingester';
        $stmt = $this->getConnection()->query($sql);
        $result = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return ['' => 'All ingesters'] // @translate
            + array_combine($result, $result);
    }

    /**
     * @return array
     */
    protected function listRenderers()
    {
        $sql = 'SELECT DISTINCT(renderer) FROM media ORDER BY renderer';
        $stmt = $this->getConnection()->query($sql);
        $result = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return ['' => 'All renderers'] // @translate
            + array_combine($result, $result);
    }

    /**
     * @return array
     */
    protected function listMediaTypes()
    {
        $sql = 'SELECT DISTINCT(media_type) FROM media WHERE media_type IS NOT NULL AND media_type != "" ORDER BY media_type';
        $stmt = $this->getConnection()->query($sql);
        $result = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return ['' => 'All media types'] // @translate
            + array_combine($result, $result);
    }

    /**
     * @param Connection $connection
     */
    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * @return \Doctrine\DBAL\Connection
     */
    protected function getConnection()
    {
        return $this->connection;
    }
}

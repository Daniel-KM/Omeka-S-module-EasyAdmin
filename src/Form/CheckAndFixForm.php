<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Common\Form\Element as CommonElement;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class CheckAndFixForm extends Form
{
    use EventManagerAwareTrait;

    public function init(): void
    {
        $this
            ->setAttribute('id', 'check-and-fix-form')
            ->appendFieldsetFilesCheckFix()
            ->appendFieldsetFilesDatabase()
            ->appendFieldsetResourceValues()
            ->appendFieldsetDatabase()
            ->appendFieldsetBackup()
            ->appendFieldsetThemes()
            ->appendFieldsetSystem()
            ->appendFieldsetTasks()
        ;

        $taskWarnings = [
            'files_missing_fix_db',
            'files_media_no_original_fix',
            'theme_templates_fix',
        ];
        $this->setAttribute('data-tasks-warning', implode(',', $taskWarnings));

        $this
            ->add([
                'name' => 'toggle_tasks_with_warning',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Display dangerous tasks', // @translate
                ],
                'attributes' => [
                    'id' => 'toggle_tasks_with_warning',
                ],
            ]);

        $event = new Event('form.add_elements', $this);
        $this->getEventManager()->triggerEvent($event);

        $inputFilter = $this->getInputFilter();

        $inputFilter->get('files_checkfix')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);
        $inputFilter->get('files_checkfix')
            ->get('files_derivative')
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

        $inputFilter->get('files_database')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);

        $inputFilter->get('resource_values')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);
        $inputFilter->get('resource_values')
            ->get('db_utf8_encode')
            ->add([
                'name' => 'type_resources',
                'required' => false,
            ]);

        $inputFilter->get('database')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);
        $inputFilter->get('database')
            ->get('db_session')
            ->add([
                'name' => 'days',
                'required' => false,
            ]);
        $inputFilter->get('database')
            ->get('db_log')
            ->add([
                'name' => 'days',
                'required' => false,
            ])
            ->add([
                'name' => 'length',
                'required' => false,
            ]);
        $inputFilter->get('database')
            ->get('db_customvocab_missing_itemsets')
            ->add([
                'name' => 'mode',
                'required' => false,
            ]);

        $inputFilter->get('backup')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);
        $inputFilter->get('backup')
            ->get('backup_install')
            ->add([
                'name' => 'include',
                'required' => false,
            ]);

        $inputFilter->get('themes')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);
        $inputFilter->get('themes')
            ->get('theme_templates')
            ->add([
                'name' => 'modules',
                'required' => false,
            ]);

        $inputFilter->get('system')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);
        $inputFilter->get('system')
            ->get('cache')
            ->add([
                'name' => 'type',
                'required' => false,
            ]);

        $inputFilter->get('module_tasks')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);

        $event = new Event('form.add_input_filters', $this, ['inputFilter' => $inputFilter]);
        $this->getEventManager()->triggerEvent($event);
    }

    protected function appendFieldsetFilesCheckFix(): self
    {
        $this
            ->add([
                'name' => 'files_checkfix',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Files', // @translate
                ],
                'attributes' => [
                    'id' => 'files_checkfix',
                    'class' => 'field-container',
                ],
            ]);

        $fieldset = $this->get('files_checkfix');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'files_excess_check' => 'List files that are present in "/files/", but not in database', // @translate
                        'files_excess_move' => 'Move files that are present in "/files/", but not in database, into "/files/check/"', // @translate
                        'files_missing_check' => 'List files that are present in database, not in "/files/" (original only)', // @translate
                        'files_missing_check_full' => 'List files that are present in database, not in "/files/" (include derivatives)', // @translate
                        'files_missing_fix' => 'Copy missing original files from the source directory below (recover a disaster)', // @translate
                        'files_missing_fix_db' => 'Remove items with one file that is missing (WARNING: export your items first)', // @translate
                        'dirs_excess' => 'Remove empty directories in "/files/" (mainly for module Archive Repertory)', // @translate
                        'files_derivative' => 'Rebuild derivative images (thumbnails)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'files_checkfix-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'files_missing',
                'options' => [
                    'label' => 'Options for fix missing files', // @translate
                ],
                'attributes' => [
                    'class' => 'files_missing_fix',
                ],
            ])
            ->get('files_missing')
            ->add([
                'name' => 'source_dir',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Source for restoration', // @translate
                ],
                'attributes' => [
                    'id' => 'files_missing-source_dir',
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
                    'id' => 'files_missing-extensions',
                    'placeholder' => 'pdf, jpeg', // @translate
                ],
            ])
            ->add([
                'name' => 'matching',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                'label' => 'Matching mode', // @translate
                    'value_options' => [
                        'sha256' => 'Hash sha256 (recommended)', // @translate
                        'source' => 'Source file path', // @translate
                        'source_filename' => 'Source file name (warning: they should be unique)', // @translate
                        'md5' => 'Hash md5 (when stored as sha256; the real sha256 must be set next with another task)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'files_missing-matching',
                ],
            ])
        ;

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'files_derivative',
                'options' => [
                    'label' => 'Options to rebuild derivative files (thumbnails)', // @translate
                ],
                'attributes' => [
                    'class' => 'files_derivative',
                ],
            ])
            ->get('files_derivative')
            ->add([
                'name' => 'item_sets',
                'type' => OmekaElement\ItemSetSelect::class,
                'options' => [
                    'label' => 'Item sets', // @translate
                ],
                'attributes' => [
                    'id' => 'files_derivative-item_sets',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'required' => false,
                    'data-placeholder' => 'Select one or more item sets…', // @translate
                ],
            ])
            ->add([
                'name' => 'ingesters',
                'type' => CommonElement\MediaIngesterSelect::class,
                'options' => [
                    'label' => 'Ingesters to process', // @translate
                    'empty_option' => 'All ingesters', // @translate
                ],
                'attributes' => [
                    'id' => 'files_derivative-ingesters',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select ingesters to process', // @ translate
                    'data-placeholder' => 'Select ingesters to process', // @ translate
                ],
            ])
            ->add([
                'name' => 'renderers',
                'type' => CommonElement\MediaRendererSelect::class,
                'options' => [
                    'label' => 'Renderers to process', // @translate
                    'empty_option' => 'All renderers', // @translate
                ],
                'attributes' => [
                    'id' => 'files_derivative-renderers',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select renderers to process', // @ translate
                    'data-placeholder' => 'Select renderers to process', // @ translate
                ],
            ])
            ->add([
                'name' => 'media_types',
                'type' => CommonElement\MediaTypeSelect::class,
                'options' => [
                    'label' => 'Media types to process', // @translate
                    'empty_option' => 'All media types', // @translate
                ],
                'attributes' => [
                    'id' => 'files_derivative-media_types',
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
                    'id' => 'files_derivative-media_ids',
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
                    'id' => 'files_derivative-original_without_thumbnails',
                ],
            ])
        ;

        return $this;
    }

    protected function appendFieldsetFilesDatabase(): self
    {
        $this
            ->add([
                'name' => 'files_database',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Files and database', // @translate
                ],
                'attributes' => [
                    'id' => 'files_database',
                    'class' => 'field-container',
                ],
            ]);

        $fieldset = $this->get('files_database');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'files_media_no_original' => 'Check media rendered as "file", but without original', // @translate
                        'files_media_no_original_fix' => 'Remove media rendered as "file", but without original (WARNING: export your media first)', // @translate
                        'files_size_check' => 'Check missing file sizes in database (not managed during upgrade to Omeka 1.2.0)', // @translate
                        'files_size_fix' => 'Fix all file sizes in database (for example after hard import)', // @translate
                        'files_hash_check' => 'Check sha256 hashes of files', // @translate
                        'files_hash_fix' => 'Fix wrong sha256 of files', // @translate
                        'files_media_type_check' => 'Check use of precise media type of files, mainly for xml', // @translate
                        'files_media_type_fix' => 'Fill precise media type of files', // @translate
                        'files_dimension_check' => 'Check files dimensions (modules IIIF Server / Image Server)', // @translate
                        'files_dimension_fix' => 'Fix files dimensions (modules IIIF Server / Image Server)', // @translate
                        'media_position_check' => 'Check positions of media (start from 1, without missing number)', // @translate
                        'media_position_fix' => 'Fix wrong positions of media', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'files_database-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetResourceValues(): self
    {
        $this
            ->add([
                'name' => 'resource_values',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Resources and values', // @translate
                ],
                'attributes' => [
                    'id' => 'resource_values',
                    'class' => 'field-container',
                ],
            ]);

        $fieldset = $this->get('resource_values');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'db_loop_save' => 'Save all resources, for example to apply new settings via triggers', // @translate
                        'db_resource_invalid_check' => 'Check if all resources are valid (items as item, etc.)', // @translate
                        'db_resource_invalid_fix' => 'Fix all resources that are not valid', // @translate
                        'db_resource_incomplete_check' => 'Check if all resources are specified as items, medias, etc.', // @translate
                        'db_resource_incomplete_fix' => 'Remove all resources that are not specified', // @translate
                        'db_item_no_value' => 'Check items without value (media values are not checked)', // @translate
                        'db_item_no_value_fix' => 'Remove items without value (files are moved into "/files/check/")', // @translate
                        'db_utf8_encode_check' => 'Check if all values are utf-8 encoded (Windows issues like "Ã©" for "é")', // @translate
                        'db_utf8_encode_fix' => 'Fix utf-8 encoding issues', // @translate
                        'db_resource_title_check' => 'Check resource titles, for example after hard import', // @translate
                        'db_resource_title_fix' => 'Update resource titles', // @translate
                        'db_item_primary_media_check' => 'Check if the primary medias are set', // @translate
                        'db_item_primary_media_fix' => 'Set the primary medias to all items', // @translate
                        'db_value_annotation_template_check' => 'Check templates for value annotations (module Advanced Resource Template)', // @translate
                        'db_value_annotation_template_fix' => 'Fix templates for value annotations (module Advanced Resource Template)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'resource_values-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_loop_save',
                'options' => [
                    'label' => 'Options to loop resources', // @translate
                ],
                'attributes' => [
                    'class' => 'db_loop_save',
                ],
            ])
            ->get('db_loop_save')
            ->add([
                'name' => 'resource_types',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Types of resources to process', // @translate
                    'value_options' => [
                        'items' => 'Items', // @translate
                        'item_sets' => 'Item sets', // @translate
                        'media' => 'Medias', // @translate
                        'value_annotations' => 'Value annotations', // @translate
                        'annotations' => 'Annotations', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'db_loop_save-resource_types',
                ],
            ])
            ->add([
                'name' => 'query',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Query to limit resources to process', // @translate
                    'info' => 'It is not recommended to use the query when multiple resource types are selected.', // @translate
                    'query_resource_type' => null,
                ],
                'attributes' => [
                    'id' => 'db_loop_save-query',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_utf8_encode',
                'options' => [
                    'label' => 'Options for utf-8 encoding (experimental: do a backup first)', // @translate
                ],
                'attributes' => [
                    'class' => 'db_utf8_encode_check db_utf8_encode_fix',
                ],
            ])
            ->get('db_utf8_encode')
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
                    'id' => 'db_utf8_encode-type_resources',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetDatabase(): self
    {
        $this
            ->add([
                'name' => 'database',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Database', // @translate
                ],
                'attributes' => [
                    'id' => 'database',
                    'class' => 'field-container',
                ],
            ]);

        $fieldset = $this->get('database');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'db_job_check' => 'Check dead jobs (living in database, but non-existent in system)', // @translate
                        'db_job_fix' => 'Set status "stopped" for jobs that never started, and "error" for the jobs that never ended', // @translate
                        'db_job_fix_all' => 'Fix status as above for all jobs (when check cannot be done after a reboot)', // @translate
                        'db_session_check' => 'Check the size of the table of sessions in database', // @translate
                        'db_session_clean' => 'Remove old sessions (specify age below)', // @translate
                        'db_session_recreate' => 'Remove all sessions (when table is too big)', // @translate
                        'db_log_check' => 'Check the size of the table of logs in database (module Log)', // @translate
                        'db_log_clean' => 'Remove old logs', // @translate
                        'db_customvocab_missing_itemsets_check' => 'Check if all custom vocabs with item sets have an existing item set', // @translate
                        'db_customvocab_missing_itemsets_clean' => 'Fix missing item sets of custom vocabs (replace or remove)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'database-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_session',
                'options' => [
                    'label' => 'Options to remove sessions', // @translate
                ],
                'attributes' => [
                    'class' => 'db_session_check db_session_clean',
                ],
            ])
            ->get('db_session')
            ->add([
                'name' => 'days',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Older than this number of days', // @translate
                ],
                'attributes' => [
                    'id' => 'db_session-days',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_log',
                'options' => [
                    'label' => 'Options to remove logs (module Log)', // @translate
                ],
                'attributes' => [
                    'class' => 'db_log_check db_log_clean',
                ],
            ])
            ->get('db_log')
            ->add([
                'name' => 'days',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Older than this number of days', // @translate
                ],
                'attributes' => [
                    'id' => 'db_log-days',
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
                    'id' => 'db_log-severity',
                ],
            ])
            ->add([
                'name' => 'length',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Maximum length of message', // @translate
                ],
                'attributes' => [
                    'id' => 'db_log-length',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_customvocab_missing_itemsets',
                'options' => [
                    'label' => 'Options for custom vocabs', // @translate
                ],
                'attributes' => [
                    'class' => 'db_customvocab_missing_itemsets_check db_customvocab_missing_itemsets_clean',
                ],
            ])
            ->get('db_customvocab_missing_itemsets')
            ->add([
                'name' => 'mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Fix mode', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'replace' => 'Replace by a standard empty custom vocab', // @translate
                        'remove' => 'Remove the custom vocab', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'db_customvocab_missing_itemsets-mode',
                    'required' => false,
                    'class' => 'db_customvocab_missing_itemsets-mode',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetBackup(): self
    {
        $this
            ->add([
                'name' => 'backup',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Backup', // @translate
                ],
                'attributes' => [
                    'id' => 'backup',
                    'class' => 'field-container',
                ],
            ]);

        $fieldset = $this->get('backup');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'backup_install' => 'Omeka, modules and themes (without directory /files)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'backup-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'backup_install',
                'options' => [
                    'label' => 'Options to backup Omeka install', // @translate
                ],
                'attributes' => [
                    'class' => 'backup_install',
                ],
            ])
            ->get('backup_install')
            ->add([
                'name' => 'include',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Include', // @translate
                    // TODO Check size first and indicate it here.
                    'value_options' => [
                        'core' => 'Omeka sources files', // @translate
                        'modules' => 'Modules', // @translate
                        'themes' => 'Themes', // @translate
                        // 'files' => 'Files (original, derivative, etc.)', // @translate
                        'logs' => 'Logs', // @translate
                        'local_config' => 'Config (local.config.php)', // @translate
                        'database_ini' => 'database.ini', // @translate
                        'htaccess' => '.htaccess', // @translate
                        'htpasswd' => '.htpasswd', // @translate
                        'hidden' => 'Hidden files (dot files)', // @translate
                        'zip' => 'Compressed files (with extension bzip, bz2, tar, gz, xz, zip)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'backup_omeka-include',
                    'value' => [
                        'core',
                        'modules',
                        'themes',
                        'logs',
                        'local_config',
                        'htaccess',
                        'hidden',
                    ],
                ],
            ])
            ->add([
                'name' => 'compression',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Compression level (-1: auto, 0: none/quick, 9: max/slow)', // @translate
                ],
                'attributes' => [
                    'id' => 'backup_omeka-compression',
                    'min' => '-1',
                    'max' => '9',
                    'value' => '-1',
                ],
            ])
        ;

        return $this;
    }

    protected function appendFieldsetThemes(): self
    {
        $this
            ->add([
                'name' => 'themes',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Themes', // @translate
                ],
                'attributes' => [
                    'id' => 'themes',
                    'class' => 'field-container',
                ],
            ]);

        $fieldset = $this->get('themes');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'theme_templates_check' => 'Check templates to migrate in themes for Omeka S v4.1', // @translate
                        'theme_templates_fix' => 'Migrate templates in themes for Omeka S v4.1 (WARNING: backup themes first)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'themes-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'theme_templates',
                'options' => [
                    'label' => 'Options to migrate templates in themes', // @translate
                ],
                'attributes' => [
                    'class' => 'theme_templates_check theme_templates_fix',
                ],
            ])
            ->get('theme_templates')
            ->add([
                'name' => 'modules',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Modules', // @translate
                    'value_options' => [
                        'Reference' => 'Reference',
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'theme_templates-modules',
                ],
            ])
        ;

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'theme_templates_warn',
                'options' => [
                    'label' => 'Check backup', // @translate
                ],
                'attributes' => [
                    'class' => 'theme_templates_fix',
                ],
            ])
            ->get('theme_templates_warn')
            ->add([
                'name' => 'backup_confirmed',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'I confirm to have an external backup of my themes and files', // @translate
                ],
                'attributes' => [
                    'id' => 'theme_templates_warn-backup_confirmed',
                ],
            ])
        ;

        return $this;
    }

    protected function appendFieldsetSystem(): self
    {
        $this
            ->add([
                'name' => 'system',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'System', // @translate
                ],
                'attributes' => [
                    'id' => 'system',
                    'class' => 'field-container',
                ],
            ]);

        $fieldset = $this->get('system');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'install_check' => 'Run installation checks (after a copy of the database on a new server)', // @translate
                        'cache_check' => 'Check caches', // @translate
                        'cache_fix' => 'Clear caches (after update or modifications of code)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'system-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'cache',
                'options' => [
                    'label' => 'Options to clear cache', // @translate
                ],
                'attributes' => [
                    'class' => 'cache_check cache_fix',
                ],
            ])
            ->get('cache')
            ->add([
                'name' => 'type',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Types of cache', // @translate
                    'value_options' => [
                        'doctrine' => 'Application (Symfony Doctrine ORM)', // @translate
                        'code' => 'Code (opcache)', // @translate
                        'data' => 'Data (apcu)', // @translate
                        'path' => 'Real paths', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'cache-type',
                    'value' => [
                        'doctrine',
                        'code',
                        'data',
                        'path',
                    ],
                ],
            ])
        ;

        return $this;
    }

    protected function appendFieldsetTasks(): self
    {
        $this
            ->add([
                'name' => 'module_tasks',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Module tasks and indexations', // @translate
                ],
                'attributes' => [
                    'id' => 'module_tasks',
                    'class' => 'field-container',
                ],
            ]);

        $fieldset = $this->get('module_tasks');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Tasks', // @translate
                    // Fix the formatting issue of the label in Omeka.
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'db_fulltext_index' => 'Omeka: Index full-text search', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'module_tasks-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        return $this;
    }
}

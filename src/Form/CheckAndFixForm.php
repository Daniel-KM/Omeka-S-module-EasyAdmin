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

    /**
     * Subject metadata (core + modules), filled in init() and during the
     * "form.add_elements" event.
     *
     * @var array<string, array>
     */
    protected $taskSubjects = [];

    /**
     * Process values of dangerous tasks (hidden until the user opts in).
     *
     * @var string[]
     */
    protected $dangerousTasks = [];

    /**
     * Let modules add their subject metadata, so their tasks get the same name
     * + description + action ui as core tasks. Call it in a listener of the
     * "form.add_elements" event.
     *
     * @param array<string, array> $subjects
     */
    public function addTaskSubjects(array $subjects): self
    {
        foreach ($subjects as $key => $data) {
            $this->taskSubjects[$key] = $data;
        }
        return $this;
    }

    /**
     * Let modules flag their dangerous tasks (process values), so they are
     * hidden until the user opts in. Call it during "form.add_elements".
     *
     * @param string[] $values
     */
    public function addDangerousTasks(array $values): self
    {
        $this->dangerousTasks = array_merge($this->dangerousTasks, array_values($values));
        return $this;
    }

    /**
     * Subject metadata merged from core and modules.
     *
     * @return array<string, array>
     */
    public function getTaskSubjects(): array
    {
        return $this->taskSubjects;
    }

    /**
     * Map of tasks grouped by subject for the intermediate ui: a short name, a
     * description covering all its actions, and the actions (process value =>
     * short verb). A subject with a single action keeps a single-option radio.
     * Strings are translated in the view. Process values not listed here fall
     * back to the legacy grouped radios.
     *
     * @return array<string, array>
     */
    public static function subjects(): array
    {
        return [
            // Files.
            'files_excess' => [
                'name' => 'Excess files', // @translate
                'description' => 'Files present in "/files/" but not referenced in the database.', // @translate
                'actions' => [
                    'files_excess_check' => 'List (originals)', // @translate
                    'files_excess_check_full' => 'List (with derivatives)', // @translate
                    'files_excess_move' => 'Move to "/files/check/"', // @translate
                ],
            ],
            'files_missing' => [
                'name' => 'Missing files', // @translate
                'description' => 'Files referenced in the database but absent from "/files/".', // @translate
                'actions' => [
                    'files_missing_check' => 'List (originals)', // @translate
                    'files_missing_check_full' => 'List (with derivatives)', // @translate
                    'files_missing_fix' => 'Restore from a source directory (crash recovery)', // @translate
                    'files_missing_fix_db' => 'Delete items and medias', // @translate
                ],
            ],
            'dirs_excess' => [
                'name' => 'Empty directories', // @translate
                'description' => 'Empty directories left in "/files/" (mainly for module Archive Repertory).', // @translate
                'actions' => [
                    'dirs_excess' => 'Delete', // @translate
                ],
            ],
            'files_derivative' => [
                'name' => 'Thumbnails (derivatives)', // @translate
                'description' => 'Recreate the derivative images (thumbnails) of files.', // @translate
                'actions' => [
                    'files_derivative' => 'Rebuild', // @translate
                    'files_derivative_file_system' => 'Rebuild via terminal (faster)', // @translate
                ],
            ],
            'files_media_no_original' => [
                'name' => 'Media without original', // @translate
                'description' => 'Media rendered as a file but with neither an original file nor thumbnails (media with thumbnails are kept).', // @translate
                'actions' => [
                    'files_media_no_original' => 'Check', // @translate
                    'files_media_no_original_fix' => 'Delete', // @translate
                ],
            ],
            // Files and database.
            'files_size' => [
                'name' => 'File sizes', // @translate
                'description' => 'Compare the file size stored in the database with the real file.', // @translate
                'actions' => [
                    'files_size_check' => 'Check', // @translate
                    'files_size_fix' => 'Fix', // @translate
                ],
            ],
            'files_hash' => [
                'name' => 'SHA-256 hashes', // @translate
                'description' => 'Compare the sha256 hash stored in the database with the real file.', // @translate
                'actions' => [
                    'files_hash_check' => 'Check', // @translate
                    'files_hash_fix' => 'Fix', // @translate
                ],
            ],
            'files_storage' => [
                'name' => 'Storage names', // @translate
                'description' => 'Hashed storage names of files.', // @translate
                'actions' => [
                    'files_storage_check' => 'Check', // @translate
                    'files_storage_fix' => 'Re-hash', // @translate
                ],
            ],
            'files_media_type' => [
                'name' => 'Media types', // @translate
                'description' => 'Precise media type of files (mainly useful for xml).', // @translate
                'actions' => [
                    'files_media_type_check' => 'Check', // @translate
                    'files_media_type_fix' => 'Fix', // @translate
                ],
            ],
            'files_dimension' => [
                'name' => 'File dimensions', // @translate
                'description' => 'Width, height and duration of image, audio and video files (modules IIIF Server / Image Server).', // @translate
                'actions' => [
                    'files_dimension_check' => 'Check', // @translate
                    'files_dimension_fix' => 'Fix', // @translate
                ],
            ],
            'media_position' => [
                'name' => 'Media positions', // @translate
                'description' => 'Position of medias inside their item (start at 1, without gap).', // @translate
                'actions' => [
                    'media_position_check' => 'Check', // @translate
                    'media_position_fix' => 'Fix', // @translate
                ],
            ],
            // Resources and values.
            'db_loop_save' => [
                'name' => 'Save all resources', // @translate
                'description' => 'Re-save resources to re-apply settings through events (for example advanced resource templates).', // @translate
                'actions' => [
                    'db_loop_save' => 'Run', // @translate
                ],
            ],
            'db_resource_invalid' => [
                'name' => 'Invalid resource types', // @translate
                'description' => 'Resources whose type does not match their data (Item, ItemSet, Media, etc.).', // @translate
                'actions' => [
                    'db_resource_invalid_check' => 'Check', // @translate
                    'db_resource_invalid_fix' => 'Fix', // @translate
                ],
            ],
            'db_resource_incomplete' => [
                'name' => 'Incomplete resources', // @translate
                'description' => 'Resource rows not specified as item, media, item set, etc.', // @translate
                'actions' => [
                    'db_resource_incomplete_check' => 'Check', // @translate
                    'db_resource_incomplete_fix' => 'Delete', // @translate
                ],
            ],
            'db_resource_orphans' => [
                'name' => 'Orphan sub-rows', // @translate
                'description' => 'Rows in sub-tables (item, media, item set, annotation, digital object…) without a matching resource.', // @translate
                'actions' => [
                    'db_resource_orphans_check' => 'Check', // @translate
                    'db_resource_orphans_fix' => 'Delete', // @translate
                ],
            ],
            'db_item_no_value' => [
                'name' => 'Items without value', // @translate
                'description' => 'Items that have no value (media values are not checked).', // @translate
                'actions' => [
                    'db_item_no_value' => 'Check', // @translate
                    'db_item_no_value_fix' => 'Delete', // @translate
                ],
            ],
            'db_utf8_encode' => [
                'name' => 'UTF-8 encoding', // @translate
                'description' => 'Values mis-encoded after a Windows import (for example "Ã©" instead of "é").', // @translate
                'actions' => [
                    'db_utf8_encode_check' => 'Check', // @translate
                    'db_utf8_encode_fix' => 'Fix', // @translate
                ],
            ],
            'db_value_clean' => [
                'name' => 'Clean values', // @translate
                'description' => 'Trim, normalize and deduplicate property values.', // @translate
                'actions' => [
                    'db_value_clean_fix' => 'Clean', // @translate
                ],
            ],
            'db_resource_title' => [
                'name' => 'Resource titles', // @translate
                'description' => 'Cached resource titles, for example after a hard import.', // @translate
                'actions' => [
                    'db_resource_title_check' => 'Check', // @translate
                    'db_resource_title_fix' => 'Update', // @translate
                ],
            ],
            'db_item_primary_media' => [
                'name' => 'Primary media', // @translate
                'description' => 'The primary media set on items.', // @translate
                'actions' => [
                    'db_item_primary_media_check' => 'Check', // @translate
                    'db_item_primary_media_fix' => 'Set', // @translate
                ],
            ],
            'db_value_annotation_template' => [
                'name' => 'Value annotation templates', // @translate
                'description' => 'Templates and classes of value annotations (module Advanced Resource Template).', // @translate
                'actions' => [
                    'db_value_annotation_template_check' => 'Check', // @translate
                    'db_value_annotation_template_fix' => 'Fix', // @translate
                ],
            ],
            // Database.
            'db_job' => [
                'name' => 'Jobs', // @translate
                'description' => 'Dead jobs, living in database but not running in the system.', // @translate
                'actions' => [
                    'db_job_check' => 'Check', // @translate
                    'db_job_fix' => 'Fix statuses', // @translate
                    'db_job_fix_all' => 'Fix all (after a reboot)', // @translate
                ],
            ],
            'db_session' => [
                'name' => 'Sessions table', // @translate
                'description' => 'Size of the sessions table in database.', // @translate
                'actions' => [
                    'db_session_check' => 'Check size', // @translate
                    'db_session_clean' => 'Delete old', // @translate
                    'db_session_recreate' => 'Delete all', // @translate
                ],
            ],
            'db_log' => [
                'name' => 'Logs table', // @translate
                'description' => 'Size of the logs table in database (module Log).', // @translate
                'actions' => [
                    'db_log_check' => 'Check size', // @translate
                    'db_log_clean' => 'Delete old', // @translate
                ],
            ],
            'db_customvocab_missing_itemsets' => [
                'name' => 'Custom vocab item sets', // @translate
                'description' => 'Custom vocabs whose linked item set no longer exists.', // @translate
                'actions' => [
                    'db_customvocab_missing_itemsets_check' => 'Check', // @translate
                    'db_customvocab_missing_itemsets_clean' => 'Fix (replace or delete)', // @translate
                ],
            ],
            'db_fulltext' => [
                'name' => 'Full-text index', // @translate
                'description' => 'Rebuild the full-text search index of resources.', // @translate
                'actions' => [
                    'db_fulltext_index' => 'Index', // @translate
                ],
            ],
            'db_orphan_tables' => [
                'name' => 'Useless tables', // @translate
                'description' => 'Tables that no active module maps, left by uninstalled modules or replaced features.', // @translate
                'actions' => [
                    'db_orphan_tables_check' => 'List', // @translate
                ],
            ],
            // Themes.
            'theme_templates' => [
                'name' => 'Theme templates (v4.1)', // @translate
                'description' => 'Templates to migrate in themes for Omeka S v4.1 (backup themes first).', // @translate
                'actions' => [
                    'theme_templates_check' => 'Check', // @translate
                    'theme_templates_fix' => 'Migrate', // @translate
                ],
            ],
            // System.
            'cache' => [
                'name' => 'Caches', // @translate
                'description' => 'Application caches.', // @translate
                'actions' => [
                    'cache_check' => 'Check', // @translate
                    'cache_fix' => 'Clear', // @translate
                ],
            ],
            'install' => [
                'name' => 'Installation', // @translate
                'description' => 'Check the consistency of the installation.', // @translate
                'actions' => [
                    'install_check' => 'Check', // @translate
                ],
            ],
            'settings_environment' => [
                'name' => 'Environment settings', // @translate
                'description' => 'Settings depending on the server to review after a copy of the database (title, urls, paths, hosts, emails, api keys…).', // @translate
                'actions' => [
                    'settings_environment_check' => 'List', // @translate
                    'settings_environment_fix' => 'Adapt for a test copy', // @translate
                ],
            ],
            // Security and privacy.
            'security' => [
                'name' => 'Security and privacy', // @translate
                'description' => 'Audit file protection, user data leaks, private data leaks and dangerous settings.', // @translate
                'actions' => [
                    'security_check' => 'Audit', // @translate
                    'security_htaccess_fix' => 'Protect directories', // @translate
                ],
            ],
            'mail' => [
                'name' => 'Email', // @translate
                'description' => 'Email configuration; sends a test email.', // @translate
                'actions' => [
                    'mail_check' => 'Check', // @translate
                ],
            ],
            'phpcli' => [
                'name' => 'Background jobs (PHP-CLI)', // @translate
                'description' => 'Check the PHP-CLI path, its version alignment with the web server and the required extensions.', // @translate
                'actions' => [
                    'phpcli_check' => 'Check', // @translate
                    'phpcli_test_job' => 'Test job', // @translate
                ],
            ],
        ];
    }

    public function init(): void
    {
        $this
            ->setAttribute('id', 'check-and-fix-form')
            ->appendFieldsetFilesCheckFix()
            ->appendFieldsetFilesDatabase()
            ->appendFieldsetResourceValues()
            ->appendFieldsetDatabase()
            ->appendFieldsetThemes()
            ->appendFieldsetSystem()
            ->appendFieldsetSecurity()
            ->appendFieldsetTasks()
        ;

        // Seed core subjects and dangerous tasks. Modules complete both during
        // the "form.add_elements" event below, so data-tasks-warning and the
        // subjects map (exposed to the view) include their tasks.
        $this->taskSubjects = self::subjects();
        $this->dangerousTasks = [
            'files_missing_fix_db',
            'files_media_no_original_fix',
            'theme_templates_check',
            'theme_templates_fix',
            'db_utf8_encode_fix',
        ];

        // Tasks that support "entity_types" option (media / digital objects).
        // The field is shown only when one of them is the selected task.
        $entityTypesTasks = [
            'files_derivative',
            'files_missing_check',
            'files_missing_check_full',
            'files_missing_fix',
            'files_missing_fix_db',
            'files_dimension_check',
            'files_dimension_fix',
            'files_hash_check',
            'files_hash_fix',
            'files_size_check',
            'files_size_fix',
            'files_storage_check',
            'files_storage_fix',
            'files_media_type_check',
            'files_media_type_fix',
        ];
        $this->setAttribute('data-entity-types-tasks', implode(',', $entityTypesTasks));

        $event = new Event('form.add_elements', $this);
        $this->getEventManager()->triggerEvent($event);

        // Built after the event so module-flagged dangerous tasks are included.
        $this->setAttribute('data-tasks-warning', implode(',', array_values(array_unique($this->dangerousTasks))));

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
            ])
            ->add([
                'name' => 'ingesters_rebuild',
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
        $inputFilter->get('resource_values')
            ->get('db_resource_title')
            ->add([
                'name' => 'report_type',
                'required' => false,
            ])
            ->add([
                'name' => 'mode',
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
        $inputFilter->get('system')
            ->get('mail')
            ->add([
                'name' => 'recipient',
                'required' => false,
            ])
            ->add([
                'name' => 'check_dns',
                'required' => false,
            ])
            ->add([
                'name' => 'domain',
                'required' => false,
            ])
            ->add([
                'name' => 'ip_address',
                'required' => false,
            ])
            ->add([
                'name' => 'dkim_selector',
                'required' => false,
            ])
            ->add([
                'name' => 'generate_dkim',
                'required' => false,
            ]);
        $inputFilter->get('system')
            ->get('settings_environment')
            ->add([
                'name' => 'include_empty',
                'required' => false,
            ]);

        $inputFilter->get('security')
            ->add([
                'name' => 'process',
                'required' => false,
            ]);

        $inputFilter->get('security')
            ->get('audit')
            ->add([
                'name' => 'probe_api',
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
                    // No label: the label of the fieldset is enough. An empty
                    // string cannot be used, because the helper FormRow of
                    // laminas-form 3.1 (Omeka S 4.1 and below) passes it to
                    // the helper formLabel, that throws a DomainException. A
                    // label that is not set is skipped by all versions.
                    'value_options' => [
                        'files_excess_check' => 'List files that are present in "/files/", but not in database (originals only)', // @translate
                        'files_excess_check_full' => 'List files that are present in "/files/", but not in database (include derivatives)', // @translate
                        'files_excess_move' => 'Move files that are present in "/files/", but not in database, into "/files/check/"', // @translate
                        'files_missing_check' => 'List files that are present in database, not in "/files/" (original only)', // @translate
                        'files_missing_check_full' => 'List files that are present in database, not in "/files/" (include derivatives)', // @translate
                        'files_missing_fix' => 'Copy missing original files from the source directory below (recover a disaster)', // @translate
                        'files_missing_fix_db' => 'Delete items with one file that is missing and missing medias (WARNING: export your items first)', // @translate
                        'dirs_excess' => 'Delete empty directories in "/files/" (mainly for module Archive Repertory)', // @translate
                        'files_derivative' => 'Rebuild derivative images (thumbnails)', // @translate
                        'files_derivative_file_system' => 'Quick rebuild all derivative images (from terminal, 10x times faster)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'files_checkfix-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ]);

        $available = $this->getOption('available_resource_types')
            ?: [
                'items',
                'item_sets',
                'media',
                'value_annotations',
                'annotations',
                'concepts',
                'digital_objects',
            ];
        $fieldset
            ->add([
                'name' => 'entity_types',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Resources to process', // @translate
                    'info' => 'Most file tasks apply to medias and, when the module is present, to digital objects.', // @translate
                    'value_options' => array_filter([
                        'media' => 'Media', // @translate
                        'digital_objects' => in_array('digital_objects', $available, true) ? 'Digital objects' : null, // @translate
                    ]),
                ],
                'attributes' => [
                    'id' => 'files_checkfix-entity_types',
                    'value' => [
                        'media',
                        'digital_objects',
                    ],
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'files_excess',
                'options' => [
                    'label' => 'Options for excess files', // @translate
                ],
                'attributes' => [
                    'class' => 'files_excess_check files_excess_check_full',
                ],
            ])
            ->get('files_excess')
            ->add([
                'name' => 'sha256',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Compute the SHA-256 of each file (to find duplicates)', // @translate
                ],
                'attributes' => [
                    'id' => 'files_excess-sha256',
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
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Limit to extensions', // @translate
                    'value_separator' => ',',
                ],
                'attributes' => [
                    'id' => 'files_missing-extensions',
                    'placeholder' => 'pdf, jpeg', // @translate
                ],
            ])
            ->add([
                'name' => 'extensions_exclude',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Exclude extensions', // @translate
                    'value_separator' => ',',
                ],
                'attributes' => [
                    'id' => 'files_missing-extensions_exclude',
                    'value' => [
                        'DS_Store',
                        'db',
                    ],
                    'placeholder' => 'DS_Store, db', // @translate
                ],
            ])
            ->add([
                'name' => 'filenames_end_exclude',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Exclude source files ending with strings', // @translate
                    'value_separator' => ',',
                ],
                'attributes' => [
                    'id' => 'files_missing-filenames_end_exclude',
                    'placeholder' => '_low, _medium', // @translate
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
            ->add([
                'name' => 'report_type',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Report type', // @translate
                    'info' => 'Full report: all files are listed (slower). Partial report: only missing and restored files are listed (faster, recommended for large collections).', // @translate
                    'value_options' => [
                        'partial' => 'Partial: only missing/restored files (faster)', // @translate
                        'full' => 'Full: all files including existing ones (slower)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'files_missing-report_type',
                    'value' => 'partial',
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
                'name' => 'thumbnails_to_create',
                'type' => Element\Radio::class,
            'options' => [
                'label' => 'Thumbnails to create', // @translate
                    'value_options' => [
                        'missing' => 'Only missing thumbnails', // @translate
                        'all' => 'All thumbnails', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'files_derivative-thumbnails_to_create',
                    'value' => 'missing',
                ],
            ])
            ->add([
                'name' => 'original_without_thumbnails',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Only originals marked without thumbnails in database', // @translate
                ],
                'attributes' => [
                    'id' => 'files_derivative-original_without_thumbnails',
                ],
            ])
            ->add([
                'name' => 'ingesters_rebuild',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Types of media to rebuild', // @translate
                    'info' => 'Uncheck "Local originals" to rebuild only remote sources. Local originals covers any media with a stored original file (file, upload, url, bulkimport, etc.). Remote thumbnails are refetched from the source for each selected ingester.', // @translate
                    'value_options' => [
                        'has_original' => 'Local originals (any ingester)', // @translate
                        'iiif' => 'IIIF Image', // @translate
                        'iiif_presentation' => 'IIIF Presentation', // @translate
                        'oembed' => 'oEmbed', // @translate
                        'url' => 'URL (refetch from source)', // @translate
                        'youtube' => 'YouTube', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'files_derivative-ingesters_rebuild',
                    'value' => ['has_original', 'iiif', 'iiif_presentation', 'oembed', 'url', 'youtube'],
                ],
            ])
        ;

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'files_derivative_file_system',
                'options' => [
                    'label' => 'Options to rebuild derivative files via file system', // @translate
                ],
                'attributes' => [
                    'class' => 'files_derivative_file_system',
                ],
            ])
            ->get('files_derivative_file_system')
            /* // TODO Implement fast check of extension and media types.
            ->add([
                'name' => 'media_types',
                'type' => CommonElement\MediaTypeSelect::class,
                'options' => [
                    'label' => 'Media types to process', // @translate
                    'empty_option' => 'All media types', // @translate
                ],
                'attributes' => [
                    'id' => 'files_derivative_file_system-media_types',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select media types to process', // @ translate
                    'data-placeholder' => 'Select media types to process', // @ translate
                ],
            ])
            */
            ->add([
                'name' => 'thumbnails_to_create',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Thumbnails to create', // @translate
                    'value_options' => [
                        'missing' => 'Only missing thumbnails', // @translate
                        'all' => 'All thumbnails', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'files_derivative_file_system-thumbnails_to_create',
                    'value' => 'missing',
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
                    // No label: the label of the fieldset is enough. An empty
                    // string cannot be used, because the helper FormRow of
                    // laminas-form 3.1 (Omeka S 4.1 and below) passes it to
                    // the helper formLabel, that throws a DomainException. A
                    // label that is not set is skipped by all versions.
                    'value_options' => [
                        'files_media_no_original' => 'Check media rendered as "file", but without original', // @translate
                        'files_media_no_original_fix' => 'Delete media rendered as "file", but without original (WARNING: export your media first)', // @translate
                        'files_size_check' => 'Check missing file sizes in database (not managed during upgrade to Omeka 1.2.0)', // @translate
                        'files_size_fix' => 'Fix all file sizes in database (for example after hard import)', // @translate
                        'files_hash_check' => 'Check sha256 hashes of files', // @translate
                        'files_hash_fix' => 'Fix wrong sha256 of files', // @translate
                        'files_storage_check' => 'Check hashes of storage names of files', // @translate
                        'files_storage_fix' => 'Re-hash storage names of files', // @translate
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

        $available = $this->getOption('available_resource_types')
            ?: [
                'items',
                'item_sets',
                'media',
                'value_annotations',
                'annotations',
                'concepts',
                'digital_objects',
            ];
        $hasValueAnnotation = in_array('value_annotations', $available, true);

        $processOptions = [
            'db_loop_save' => 'Save all resources, for example to apply new settings via triggers', // @translate
            'db_resource_invalid_check' => 'Check if all resource have valid types. (Item, ItemSet, etc)', // @translate
            'db_resource_invalid_fix' => 'Fix all resource with invalid types.', // @translate
            'db_resource_incomplete_check' => 'Check if all resources are specified as items, medias, etc.', // @translate
            'db_resource_incomplete_fix' => 'Delete all resources that are not specified', // @translate
            'db_resource_orphans_check' => 'Check rows in sub-tables (item, media, item_set, value_annotation, annotation, digital_object) without a matching resource', // @translate
            'db_resource_orphans_fix' => 'Delete orphan rows in sub-tables without a matching resource', // @translate
            'db_item_no_value' => 'Check items without value (media values are not checked)', // @translate
            'db_item_no_value_fix' => 'Delete items without value (files are moved into "/files/check/")', // @translate
            'db_utf8_encode_check' => 'Check if all values are utf-8 encoded (Windows issues like "Ã©" for "é")', // @translate
            'db_utf8_encode_fix' => 'Fix utf-8 encoding issues', // @translate
            'db_value_clean_fix' => 'Clean and deduplicate values', // @translate
            'db_resource_title_check' => 'Check resource titles, for example after hard import', // @translate
            'db_resource_title_fix' => 'Update resource titles', // @translate
            'db_item_primary_media_check' => 'Check if the primary medias are set', // @translate
            'db_item_primary_media_fix' => 'Set the primary medias to all items', // @translate
        ];
        if ($hasValueAnnotation) {
            $processOptions['db_value_annotation_template_check'] = 'Check templates for value annotations (module Advanced Resource Template)'; // @translate
            $processOptions['db_value_annotation_template_fix'] = 'Fix templates for value annotations (module Advanced Resource Template)'; // @translate
        }

        $resourceTypeLabels = [
            'all' => 'All', // @translate
            'items' => 'Items', // @translate
            'item_sets' => 'Item sets', // @translate
            'media' => 'Medias', // @translate
            'value_annotations' => 'Value annotations', // @translate
            'annotations' => 'Annotations', // @translate
            'concepts' => 'Concepts', // @translate
            'digital_objects' => 'Digital objects', // @translate
        ];
        $resourceTypeOptions = ['all' => $resourceTypeLabels['all']];
        foreach ($available as $apiName) {
            if (isset($resourceTypeLabels[$apiName])) {
                $resourceTypeOptions[$apiName] = $resourceTypeLabels[$apiName];
            }
        }

        $fieldset = $this->get('resource_values');
        $fieldset
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    // No label: the label of the fieldset is enough. An empty
                    // string cannot be used, because the helper FormRow of
                    // laminas-form 3.1 (Omeka S 4.1 and below) passes it to
                    // the helper formLabel, that throws a DomainException. A
                    // label that is not set is skipped by all versions.
                    'value_options' => $processOptions,
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
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Type of resources to process', // @translate
                    'value_options' => $resourceTypeOptions,
                ],
                'attributes' => [
                    'id' => 'db_loop_save-resource_types',
                    'value' => 'items',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'query',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Query to limit resources to process', // @translate
                    'info' => 'The query is scoped to the selected resource type. With "All", it is applied identically to every type and unsupported filters are ignored.', // @translate
                    'query_resource_type' => 'items',
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
                    'label' => 'Records to process (experimental: back up before fixing)', // @translate
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
                    'value' => ['all'],
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_value_clean',
                'options' => [
                    'label' => 'Options to clean and deduplicate', // @translate
                ],
                'attributes' => [
                    'class' => 'db_value_clean_check db_value_clean_fix',
                ],
            ])
            ->get('db_value_clean')
            ->add([
                'name' => 'actions',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Actions to process', // @translate
                    'value_options' => [
                        'trim' => 'Trim property values', // @translate
                        // 'specify_datatype' => 'Specify data type  for linked resources', // @translate
                        'null_empty_value' => 'Set null for empty values or uris', // @translate
                        'null_empty_language' => 'Set null when language is empty', // @translate
                        'deduplicate' => 'Deduplicate property values case insensitively', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'db_value_clean-actions',
                    'value' => [
                        'trim',
                        // 'specify_datatype',
                        'null_empty_value',
                        'null_empty_language',
                        // 'deduplicate',
                    ],
                ],
            ])
            ->add([
                'name' => 'resource_types',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Type of resources to process', // @translate
                    'value_options' => $resourceTypeOptions,
                ],
                'attributes' => [
                    'id' => 'db_value_clean-resource_types',
                    'value' => 'items',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'query',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Query to limit resources to process', // @translate
                    'info' => 'The query is scoped to the selected resource type. With "All", it is applied identically to every type and unsupported filters are ignored.', // @translate
                    'query_resource_type' => 'items',
                ],
                'attributes' => [
                    'id' => 'db_value_clean-query',
                ],
            ]);

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'db_resource_title',
                'options' => [
                    'label' => 'Options for resource titles', // @translate
                ],
                'attributes' => [
                    'class' => 'db_resource_title_check db_resource_title_fix',
                ],
            ])
            ->get('db_resource_title')
            ->add([
                'name' => 'report_type',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Report type', // @translate
                    'info' => 'Full report: all resources are listed (slower). Partial report: only resources with different titles are listed (faster, recommended for large collections).', // @translate
                    'value_options' => [
                        'partial' => 'Partial: only different titles (faster)', // @translate
                        'full' => 'Full: all resources (slower)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'db_resource_title-report_type',
                    'value' => 'partial',
                ],
            ])
            ->add([
                'name' => 'mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Update mode (fix only)', // @translate
                    'info' => 'Direct mode: fast update via database, but does not trigger Omeka events (modules like AdvancedResourceTemplate will not update titles). API mode: slower but triggers all events and modules, ensuring full consistency.', // @translate
                    'value_options' => [
                        'direct' => 'Direct (fast, no events)', // @translate
                        'api' => 'API (slower, triggers all events and modules)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'db_resource_title-mode',
                    'value' => 'direct',
                    'class' => 'db_resource_title_fix',
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
                    // No label: the label of the fieldset is enough. An empty
                    // string cannot be used, because the helper FormRow of
                    // laminas-form 3.1 (Omeka S 4.1 and below) passes it to
                    // the helper formLabel, that throws a DomainException. A
                    // label that is not set is skipped by all versions.
                    'value_options' => [
                        'db_job_check' => 'Check dead jobs (living in database, but non-existent in system)', // @translate
                        'db_job_fix' => 'Set status "stopped" for jobs that never started, and "error" for the jobs that never ended', // @translate
                        'db_job_fix_all' => 'Fix status as above for all jobs (when check cannot be done after a reboot)', // @translate
                        'db_session_check' => 'Check the size of the table of sessions in database', // @translate
                        'db_session_clean' => 'Delete old sessions', // @translate
                        'db_session_recreate' => 'Delete all sessions (when table is too big)', // @translate
                        'db_log_check' => 'Check the size of the table of logs in database (module Log)', // @translate
                        'db_log_clean' => 'Delete old logs', // @translate
                        'db_customvocab_missing_itemsets_check' => 'Check if all custom vocabs with item sets have an existing item set', // @translate
                        'db_customvocab_missing_itemsets_clean' => 'Fix missing item sets of custom vocabs', // @translate
                        'db_orphan_tables_check' => 'List useless tables (left by uninstalled modules or replaced features: reference_metadata, triplestore, term…)', // @translate
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
                    'label' => 'Options to delete sessions', // @translate
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
                    'label' => 'Options to delete logs (module Log)', // @translate
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
                        'remove' => 'Delete the custom vocab', // @translate
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
                    // No label: the label of the fieldset is enough. An empty
                    // string cannot be used, because the helper FormRow of
                    // laminas-form 3.1 (Omeka S 4.1 and below) passes it to
                    // the helper formLabel, that throws a DomainException. A
                    // label that is not set is skipped by all versions.
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
                    // No label: the label of the fieldset is enough. An empty
                    // string cannot be used, because the helper FormRow of
                    // laminas-form 3.1 (Omeka S 4.1 and below) passes it to
                    // the helper formLabel, that throws a DomainException. A
                    // label that is not set is skipped by all versions.
                    'value_options' => [
                        'install_check' => 'Run installation checks (after a copy of the database on a new server)', // @translate
                        'settings_environment_check' => 'List environment-specific settings to review after a copy of the database (title, urls, paths, hosts, emails, api keys…)', // @translate
                        'settings_environment_fix' => 'Adapt settings for a test/dev copy (prefix the title with « TEST », then list the other settings to review)', // @translate
                        'cache_check' => 'Check caches', // @translate
                        'cache_fix' => 'Clear caches (after update or modifications of code)', // @translate
                        'mail_check' => 'Check email configuration and send test email', // @translate
                        'phpcli_check' => 'Check the PHP-CLI used by background jobs (path, version alignment with the web server, extensions) and suggest the correct path', // @translate
                        'phpcli_test_job' => 'Run a test job in the background to check the whole pipeline end to end', // @translate
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

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'mail',
                'options' => [
                    'label' => 'Options for email check', // @translate
                ],
                'attributes' => [
                    'class' => 'mail_check',
                ],
            ])
            ->get('mail')
            ->add([
                'name' => 'recipient',
                'type' => Element\Email::class,
                'options' => [
                    'label' => 'Recipient email for test', // @translate
                ],
                'attributes' => [
                    'id' => 'mail-recipient',
                    'placeholder' => 'test@example.org', // @translate
                ],
            ])
            ->add([
                'name' => 'check_dns',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Check DNS records (SPF, DKIM, DMARC)', // @translate
                    'info' => 'Check the DNS records of the mail domain to help configure your registrar and mail server.', // @translate
                ],
                'attributes' => [
                    'id' => 'mail-check-dns',
                ],
            ])
            ->add([
                'name' => 'domain',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Domain (optional)', // @translate
                    'info' => 'The domain to check DNS records for. If not provided, it will be extracted from the sender email.', // @translate
                ],
                'attributes' => [
                    'id' => 'mail-domain',
                    'placeholder' => 'example.org',
                ],
            ])
            ->add([
                'name' => 'ip_address',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Mail server IP address (optional, auto-detected)', // @translate
                    'info' => 'The IP address of your mail server. If empty, it will be auto-detected from SMTP config, server IP, or domain A record.', // @translate
                ],
                'attributes' => [
                    'id' => 'mail-ip-address',
                    'placeholder' => '192.0.2.1',
                ],
            ])
            ->add([
                'name' => 'dkim_selector',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'DKIM selector (optional)', // @translate
                    'info' => 'The DKIM selector used in your DNS records (e.g., "mail", "default", "selector1"). Default is "mail".', // @translate
                ],
                'attributes' => [
                    'id' => 'mail-dkim-selector',
                    'placeholder' => 'mail',
                ],
            ])
            ->add([
                'name' => 'generate_dkim',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Generate DKIM key if missing', // @translate
                    'info' => 'Generate a new DKIM key pair using OpenSSL. The public key will be shown for DNS configuration.', // @translate
                ],
                'attributes' => [
                    'id' => 'mail-generate-dkim',
                ],
            ])
        ;

        $fieldset
            ->add([
                'type' => Fieldset::class,
                'name' => 'settings_environment',
                'options' => [
                    'label' => 'Options for environment settings', // @translate
                ],
                'attributes' => [
                    'class' => 'settings_environment_check settings_environment_fix',
                ],
            ])
            ->get('settings_environment')
            ->add([
                'name' => 'include_empty',
                'type' => \Common\Form\Element\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Include empty settings', // @translate
                    'info' => 'List settings whose value is currently empty (they may need a server-specific value).', // @translate
                ],
                'attributes' => [
                    'id' => 'settings-environment-include-empty',
                ],
            ])
        ;

        return $this;
    }

    protected function appendFieldsetSecurity(): self
    {
        $this
            ->add([
                'name' => 'security',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Security and privacy', // @translate
                ],
                'attributes' => [
                    'id' => 'security',
                    'class' => 'field-container',
                ],
            ]);

        $this->get('security')
            ->add([
                'name' => 'process',
                'type' => Element\Radio::class,
                'options' => [
                    // No label: the label of the fieldset is enough. An empty
                    // string cannot be used, because the helper FormRow of
                    // laminas-form 3.1 (Omeka S 4.1 and below) passes it to
                    // the helper formLabel, that throws a DomainException. A
                    // label that is not set is skipped by all versions.
                    'value_options' => [
                        'security_check' => 'Audit the security and privacy of the system (file protection, user data leaks, private data leaks, dangerous settings)', // @translate
                        'security_htaccess_fix' => 'Protect the sensitive directories of "files/" with a ".htaccess" (backups, imports, exports, logs, contributions, user data…)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'security-process',
                    'required' => false,
                    'class' => 'fieldset-process',
                ],
            ])
            ->add([
                'name' => 'audit',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Options for the audit', // @translate
                ],
                'attributes' => [
                    'class' => 'security_check',
                ],
            ])
            ->get('audit')
            ->add([
                'name' => 'probe_api',
                'type' => \Common\Form\Element\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Probe the api anonymously', // @translate
                    'info' => 'Send two http requests to this install to check that the api does not expose user emails or private resources to anonymous visitors. Uncheck it when the server does not answer to itself: each request waits up to five seconds.', // @translate
                ],
                'attributes' => [
                    'id' => 'security-probe-api',
                    'value' => true,
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
                    // No label: the label of the fieldset is enough. An empty
                    // string cannot be used, because the helper FormRow of
                    // laminas-form 3.1 (Omeka S 4.1 and below) passes it to
                    // the helper formLabel, that throws a DomainException. A
                    // label that is not set is skipped by all versions.
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

<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Easy Admin'; // @translate

    protected $elementGroups = [
        'easy_admin' => 'Easy Admin', // @translate
        'maintenance' => 'Maintenance', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'easy-admin')
            ->setOption('element_groups', $this->elementGroups)

            ->add([
                'name' => 'easyadmin_interface',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Elements to display in resources admin pages', // @translate
                    'info' => 'For button "Previous/Next", an issue exists on some versions of mysql database. Mariadb is working fine.', // @translate
                    'value_options' => [
                        'resource_public_view' => 'Button "Public view"', // @translate
                        'resource_previous_next' => 'Buttons "Previous/Next"', // @translate
                    ],
                    'use_hidden_element' => true,
                ],
                'attributes' => [
                    'id' => 'easyadmin_interface',
                ],
            ])

            ->add([
                'name' => 'easyadmin_local_path',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Folder for pre-uploaded files', // @translate
                    'info' => 'For security reasons, local files to import should be inside this folder.', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_local_path',
                ],
            ])
            ->add([
                'name' => 'easyadmin_allow_empty_files',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Allow empty files in manual upload', // @translate
                    'info' => 'In rare cases, an admin may want to upload empty files. This option requires to disable file validation or to add the media type "application/x-empty" in main settings.', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_allow_empty_files',
                ],
            ])
            ->add([
                'name' => 'easyadmin_addon_notify_version_inactive',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Notify new versions for inactive modules', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_addon_notify_version_inactive',
                ],
            ])
            ->add([
                'name' => 'easyadmin_addon_notify_version_dev',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Notify development versions of modules', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_addon_notify_version_dev',
                ],
            ])

            ->add([
                'name' => 'easyadmin_content_lock',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Enable content lock to avoid simultaneous editing', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_content_lock',
                ],
            ])
            ->add([
                'name' => 'easyadmin_content_lock_duration',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Number of seconds before automatic removing of the lock', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_content_lock_duration',
                ],
            ])

            ->add([
                'name' => 'easyadmin_selections_modules',
                'type' => CommonElement\GroupTextarea::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Curated selections or modules', // @translate
                    'info' => 'Use the canonical name of the module (case sensitive, without space)', // @translate
                    'default_group_name' => 'Selection %s',
                ],
                'attributes' => [
                    'id' => 'easyadmin_selections_modules',
                    'rows' => 12,
                    'placeholder' => <<<'TXT'
                        # Database for researcher
                        Common
                        CustomOntology
                        CustomVocab
                        DataTypeRdf
                        EasyAdmin
                        Log
                        NumericDataTypes
                        
                        # Standard digital library for libraries
                        AdvancedResourceTemplate
                        AdvancedSearch
                        BlockPlus
                        BulkEdit
                        BulkExport
                        BulkImport
                        Coins
                        Common
                        ContactUs
                        CustomOntology
                        CustomVocab
                        DataTypeRdf
                        EasyAdmin
                        IiifServer
                        ImageServer
                        Log
                        Mirador
                        NumericDataTypes
                        OaiPmhRepository
                        Reference
                        ValueSuggest
                        TXT,
                ],
            ])

            ->add([
                'name' => 'easyadmin_maintenance_mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'maintenance',
                    'label' => 'Set Omeka under maintenance', // @translate
                    'value_options' => [
                        '' => 'No', // @translate
                        'public' => 'Public front-end', // @translate
                        'admin' => 'Admin (except global admins)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'easyadmin_maintenance_mode',
                    'required' => false,
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'easyadmin_maintenance_text',
                'type' => OmekaElement\CkeditorInline::class,
                'options' => [
                    'element_group' => 'maintenance',
                    'label' => 'Text to display for maintenance', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_maintenance_text',
                    'rows' => 12,
                    'placeholder' => 'This site is down for maintenance. Please contact the site administrator for more information.', // @translate
                ],
            ]);
    }
}

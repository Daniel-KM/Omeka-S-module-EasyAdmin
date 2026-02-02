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
                'name' => 'easyadmin_administrator_name',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'general',
                    'label' => 'Administrator name', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_administrator_name',
                ],
            ])

            ->add([
                'name' => 'easyadmin_no_reply_email',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'general',
                    'label' => 'No reply email for automatic messages and notifications', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_no_reply_email',
                ],
            ])

            ->add([
                'name' => 'easyadmin_no_reply_name',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'general',
                    'label' => 'No reply name', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_no_reply_name',
                ],
            ])

            ->add([
                'name' => 'easyadmin_interface',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'display',
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
                'name' => 'easyadmin_rights_reviewer_delete_all',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Allow the reviewer to delete any resource', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_rights_reviewer_delete_all',
                ],
            ])

            ->add([
                'name' => 'easyadmin_quick_template',
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Add a button to create resources directly from a template', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'easyadmin_quick_template',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resource templates…', // @translate'
                ],
            ])

            ->add([
                'name' => 'easyadmin_quick_class',
                'type' => CommonElement\OptionalResourceClassSelect::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Add a button to create resources directly from a template specified with a class', // @translate
                    'query' => ['used' => true],
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'easyadmin_quick_class',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resource classes…', // @translate'
                ],
            ])

            ->add([
                'name' => 'easyadmin_asset_media_types',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Asset: allowed media types', // @translate
                    'info' => 'Additional media types allowed for assets. Common image types (jpeg, png, gif, webp) are included by default. One media type per line.', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_asset_media_types',
                    'placeholder' => "image/apng\nimage/avif\nimage/svg+xml\napplication/pdf",
                ],
            ])
            ->add([
                'name' => 'easyadmin_asset_extensions',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Asset: allowed extensions', // @translate
                    'info' => 'Additional extensions allowed for assets. Common image extensions (jpg, jpeg, png, gif, webp) are included by default. Separate with spaces.', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_asset_extensions',
                    'placeholder' => 'apng avif svg pdf',
                ],
            ])

            ->add([
                'name' => 'easyadmin_local_path',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Default upload folder', // @translate
                    'info' => 'Default folder for file uploads. Must be inside /files but not in protected directories (original, asset, large, medium, square).', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_local_path',
                    'placeholder' => '/var/www/html/files/upload', // @translate
                ],
            ])
            ->add([
                'name' => 'easyadmin_local_paths',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Additional folders', // @translate
                    'info' => 'Additional folders available in the file manager. One path per line. Protected Omeka directories (original, asset, derivatives) are always available for browsing but are read-only.', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_local_paths',
                    'rows' => 3,
                ],
            ])
            ->add([
                'name' => 'easyadmin_disable_csrf',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Disable CSRF check for uploads', // @translate
                    'info' => 'May be required with some VPN/proxy configurations.', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_disable_csrf',
                ],
            ])
            ->add([
                'name' => 'easyadmin_allow_empty_files',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'easy_admin',
                    'label' => 'Allow empty files', // @translate
                    'info' => 'Allow uploading empty files. Requires disabling file validation or adding media type "application/x-empty" in main settings.', // @translate
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
                'name' => 'easyadmin_display_exception',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'maintenance',
                    'label' => 'Display error on screen', // @translate
                    'info' => 'This option helps fix issues and should be unset in production. For development, use the key "APPLICATION_ENV" in file .htaccess.', // @translate
                ],
                'attributes' => [
                    'id' => 'easyadmin_display_exception',
                    'required' => false,
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
            ])
        ;
    }
}

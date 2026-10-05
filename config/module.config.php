<?php declare(strict_types=1);

namespace EasyAdmin;

return [
    // Use curl (HTTP/2) when available on older Omeka cores that pin a socket
    // adapter. The adapter must remain a valid class, because the config may be
    // passed directly to a laminas http client by other modules.
    'http_client' => [
        'adapter' => extension_loaded('curl')
            ? \Laminas\Http\Client\Adapter\Curl::class
            : \Laminas\Http\Client\Adapter\Socket::class,
        'curloptions' => [],
    ],
    'service_manager' => [
        'invokables' => [
            Mvc\MvcListeners::class => Mvc\MvcListeners::class,
        ],
        'factories' => [
            'Omeka\HttpClient' => Service\HttpClientFactory::class,
            'EasyAdmin\File\Thumbnailer\Configured' => Service\File\Thumbnailer\ConfiguredThumbnailerFactory::class,
        ],
        'delegators' => [
            'Omeka\File\Store\Local' => [
                Service\File\Store\LocalStoreBaseUriDelegatorFactory::class,
            ],
        ],
    ],
    'api_adapters' => [
        'delegators' => [
            \Omeka\Api\Adapter\AssetAdapter::class => [
                Service\Delegator\AssetAdapterDelegatorFactory::class,
            ],
        ],
    ],
    'listeners' => [
        Mvc\MvcListeners::class,
    ],
    'media_ingesters' => [
        'invokables' => [
            'bulk_uploaded' => Media\Ingester\BulkUploaded::class,
        ],
        'factories' => [
            'bulk_upload' => Service\MediaIngester\BulkUploadFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'lastBrowsePage' => View\Helper\LastBrowsePage::class,
        ],
        'factories' => [
            'nextResource' => Service\ViewHelper\NextResourceFactory::class,
            'previousNext' => Service\ViewHelper\PreviousNextFactory::class,
            'previousResource' => Service\ViewHelper\PreviousResourceFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
            Form\AddonManageForm::class => Form\AddonManageForm::class,
            Form\ModuleStateForm::class => Form\ModuleStateForm::class,
        ],
        'factories' => [
            Form\CheckAndFixForm::class => Service\Form\CheckAndFixFormFactory::class,
            Form\CronForm::class => Service\Form\CronFormFactory::class,
            // Fix #2236 for Omeka < 4.2 (removed dynamically in Module::getConfig() for 4.2+).
            'Omeka\Form\AssetEditForm' => Service\Form\FormWithEventManagerFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'EasyAdmin\Controller\Admin\CheckAndFix' => Controller\Admin\CheckAndFixController::class,
            'EasyAdmin\Controller\Admin\Cron' => Controller\Admin\CronController::class,
            'Omeka\Controller\Admin\Maintenance' => Controller\Admin\MaintenanceController::class,
        ],
        'factories' => [
            // Class is not used as key, since it's set dynamically by sub-route
            // and it should be available in acl (so alias is mapped later).
            'EasyAdmin\Controller\Admin\Backup' => Service\Controller\BackupControllerFactory::class,
            'EasyAdmin\Controller\Admin\FileManager' => Service\Controller\FileManagerControllerFactory::class,
            'EasyAdmin\Controller\Upload' => Service\Controller\UploadControllerFactory::class,
            'EasyAdmin\Controller\Admin\Module' => Service\Controller\ModuleControllerFactory::class,
            'EasyAdmin\Controller\Admin\Theme' => Service\Controller\ThemeControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'checkMailer' => Service\ControllerPlugin\CheckMailerFactory::class,
            'easyAdminAddons' => Service\ControllerPlugin\AddonsFactory::class,
        ],
    ],
    // Custom routes for /admin/easy-admin/*.
    // Refactoring to admin/default would break urls.
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'easy-admin' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/easy-admin',
                            'defaults' => [
                                '__NAMESPACE__' => 'EasyAdmin\Controller\Admin',
                                '__ADMIN__' => true,
                                'controller' => 'CheckAndFix',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:controller[/:action]',
                                    'constraints' => [
                                        'controller' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                    'defaults' => [
                                        'controller' => 'CheckAndFix',
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                            'backup' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/backup[/:action]',
                                    'constraints' => [
                                        'action' => '[a-zA-Z-]+',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'EasyAdmin\Controller\Admin',
                                        'controller' => 'Backup',
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                            'file-manager' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/file-manager[/:action]',
                                    'constraints' => [
                                        'action' => '[a-zA-Z-]+',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'EasyAdmin\Controller\Admin',
                                        'controller' => 'FileManager',
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'upload' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/upload[/:action]',
                                    'constraints' => [
                                        'action' => 'index|upload',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'EasyAdmin\Controller',
                                        'controller' => 'Upload',
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                            // Cron route - EasyAdmin's controller checks if Cron module
                            // is installed and forwards appropriately.
                            'cron' => [
                                'type' => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route' => '/cron',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'EasyAdmin\Controller\Admin',
                                        'controller' => 'Cron',
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminGlobal' => [
            'easy-admin' => [
                'label' => 'Easy Admin', // @translate
                'route' => 'admin/easy-admin/default',
                'controller' => 'check-and-fix',
                'resource' => 'Omeka\Controller\Admin\Module',
                'privilege' => 'browse',
                'class' => 'o-icon- fa-tools',
                'pages' => [
                    [
                        'label' => 'Checks and fixes', // @translate
                        'route' => 'admin/easy-admin/default',
                        'controller' => 'check-and-fix',
                        'class' => 'o-icon- fa-wrench',
                    ],
                    [
                        'label' => 'Regular tasks', // @translate
                        'route' => 'admin/easy-admin/cron',
                        'resource' => 'EasyAdmin\Controller\Admin\Cron',
                        'class' => 'o-icon- fa-recycle',
                    ],
                    [
                        // Not "Upload" because translation is not good here.
                        'label' => 'File manager', // @translate
                        'route' => 'admin/easy-admin/file-manager',
                        'action' => 'browse',
                        'resource' => 'EasyAdmin\Controller\Admin\FileManager',
                        'class' => 'o-icon- fa-folder-open',
                    ],
                    [
                        'label' => 'Backup', // @translate
                        'route' => 'admin/easy-admin/backup',
                        'action' => 'index',
                        'resource' => 'EasyAdmin\Controller\Admin\Backup',
                        'class' => 'o-icon- fa-archive',
                    ],
                    [
                        'label' => 'Modules', // @translate
                        'route' => 'admin/easy-admin/default',
                        'controller' => 'module',
                        'resource' => 'EasyAdmin\Controller\Admin\Module',
                        'class' => 'o-icon- fa-cubes',
                    ],
                    [
                        'label' => 'Themes', // @translate
                        'route' => 'admin/easy-admin/default',
                        'controller' => 'theme',
                        'resource' => 'EasyAdmin\Controller\Admin\Theme',
                        'class' => 'o-icon- fa-paint-brush',
                    ],
                ],
            ],
        ],
        'EasyAdmin' => [
            [
                'label' => 'Checks and fixes', // @translate
                'route' => 'admin/easy-admin/default',
                'controller' => 'check-and-fix',
                'pages' => [
                    [
                        'route' => 'admin/easy-admin',
                        'visible' => false,
                    ],
                ],
            ],
            [
                'label' => 'Regular tasks', // @translate
                'route' => 'admin/easy-admin/cron',
                'resource' => 'EasyAdmin\Controller\Admin\Cron',
            ],
            [
                'label' => 'File manager', // @translate
                'route' => 'admin/easy-admin/file-manager',
                'action' => 'browse',
                'resource' => 'EasyAdmin\Controller\Admin\FileManager',
            ],
            [
                'label' => 'Backup', // @translate
                'route' => 'admin/easy-admin/backup',
                'action' => 'index',
                'resource' => 'EasyAdmin\Controller\Admin\Backup',
            ],
            [
                'label' => 'Modules', // @translate
                'route' => 'admin/easy-admin/default',
                'controller' => 'module',
                'resource' => 'EasyAdmin\Controller\Admin\Module',
            ],
            [
                'label' => 'Themes', // @translate
                'route' => 'admin/easy-admin/default',
                'controller' => 'theme',
                'resource' => 'EasyAdmin\Controller\Admin\Theme',
            ],
        ],
    ],
    'assets' => [
        // Override internals assets. Only for Omeka assets: modules can use another filename.
        'internals' => [
            'vendor/chosen-js/chosen.css' => 'EasyAdmin',
            'vendor/chosen-js/chosen.jquery.js' => 'EasyAdmin',
            'js/chosen-options.js' => 'EasyAdmin',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'easyadmin' => [
        'config' => [
            'easyadmin_local_path_any_files' => false,
            // For Cron. Default is ['session_8'], set during install.
            'easyadmin_cron_tasks' => [],
            // Hidden option, storing last processed cron time.
            'easyadmin_cron_last' => null,
            // This option should be set in local.config.php.
            // For security, it should not be set in production or check rights.
            'easyadmin_local_path_any' => false,
        ],
        'settings' => [
            // General.
            'easyadmin_administrator_name' => '',
            'easyadmin_no_reply_email' => '',
            'easyadmin_no_reply_name' => '',
            // Display.
            'easyadmin_interface' => [
                'resource_public_view',
                // 'resource_previous_next',
            ],
            'easyadmin_settings_enhancements' => false,
            'easyadmin_admin_board_color' => '',
            // Editing.
            'easyadmin_rights_reviewer_delete_all' => false,
            'easyadmin_quick_template' => [],
            'easyadmin_quick_class' => [],
            // Easy admin.
            'easyadmin_local_path' => OMEKA_PATH . '/files/import',
            'easyadmin_local_paths' => [],
            'easyadmin_user_directories' => false,
            'easyadmin_disable_csrf' => false,
            'easyadmin_allow_empty_files' => false,
            'easyadmin_config_apply_button' => false,
            'easyadmin_local_store_base_uri' => '',
            'easyadmin_addon_notify_version_inactive' => true,
            'easyadmin_addon_notify_version_dev' => false,
            'easyadmin_display_exception' => '',
            // Assets: additional allowed media types and extensions.
            'easyadmin_asset_media_types' => [
                // 'application/pdf',
                // 'image/svg+xml',
            ],
            'easyadmin_asset_extensions' => [
                // 'pdf',
                // 'svg',
            ],
            // Maintenance
            'easyadmin_maintenance_mode' => '',
            'easyadmin_maintenance_text' => 'This site is down for maintenance. Please contact the site administrator for more information.', // @translate
        ],
        // Known core defaults, so the settings filter flags each core field as
        // default or modified instead of unknown. Values are the ones stored at
        // installation or used as fallback by the core when a setting is unset
        // (second argument of the "get()" calls in the core forms). Settings of
        // modules that do not declare their defaults are absent here and stay
        // unknown.
        // @see \Omeka\Installation\Task\AddDefaultSettingsTask
        // @see \Omeka\Form\SettingForm
        // @see \Omeka\Form\SiteSettingsForm
        'core_settings_defaults' => [
            'settings' => [
                'pagination_per_page' => \Omeka\Stdlib\Paginator::PER_PAGE,
                'version_notifications' => '1',
                'use_htmlpurifier' => '1',
                'media_type_whitelist' => \Omeka\Form\SettingForm::MEDIA_TYPE_WHITELIST,
                'extension_whitelist' => \Omeka\Form\SettingForm::EXTENSION_WHITELIST,
                // Not clear: install uses date.timezone, else "UTC".
                'time_zone' => 'UTC',
                'property_label_information' => 'none',
                'batch_chunk_size' => 100,
                'default_to_private_items' => false,
                'default_to_private_item_sets' => false,
                'default_to_private_sites' => false,
                'default_to_private_site_pages' => false,
                'value_languages' => [],
                'disable_jsonld_reverse' => '',
                'disable_jsonld_embed' => '',
                'favicon' => '',
                'default_site' => '',
                'media_alt_text_property' => '',
                'disable_file_validation' => '',
                // Not clear: empty follows the browser locale.
                'locale' => '',
                'recaptcha_site_key' => '',
                'recaptcha_secret_key' => '',
                // Not clear: set by the user at installation, probably empty.
                'administrator_email' => '',
                'installation_title' => '',
            ],
            'site_settings' => [
                'o:assign_new_items' => true,
                'show_page_pagination' => true,
                'show_user_bar' => '0',
                'show_locale_label' => true,
                'show_attached_pages' => true,
                'filter_locale_values' => false,
                'browse_attached_items' => false,
                'exclude_resources_not_in_site' => false,
                'item_media_embed' => false,
                'property_label_information' => 'none',
                'search_type' => 'sitewide',
                'search_resource_names' => ['site_pages', 'items'],
                'search_apply_templates' => [],
                'disable_jsonld_embed' => '',
                'favicon' => '',
                'locale' => '',
                // Not clear, probably empty: no fallback in the core form.
                'show_value_annotations' => '',
                'vocabulary_scope' => '',
                // Not clear, probably empty: inherited, theme dependent or computed.
                'pagination_per_page' => '',
                'attachment_link_type' => '',
                'subnav_display' => '',
                'browse_heading_property_term' => '',
                'browse_body_property_term' => '',
                'browse_defaults_public_items' => '',
            ],
        ],
    ],
    // Cron tasks registered with the Cron module.
    'cron_tasks' => [
        'session' => [
            'label' => 'Clear old sessions', // @translate
            'module' => 'EasyAdmin',
            'task_type' => 'builtin',
            'frequencies' => ['hourly', 'daily', 'weekly'],
            'default_frequency' => 'daily',
            'params' => [
                'age' => [
                    'label' => 'Session age', // @translate
                    'options' => [
                        '1h' => 'older than 1 hour', // @translate
                        '2h' => 'older than 2 hours', // @translate
                        '4h' => 'older than 4 hours', // @translate
                        '12h' => 'older than 12 hours', // @translate
                        '1d' => 'older than 1 day', // @translate
                        '2d' => 'older than 2 days', // @translate
                        '8d' => 'older than 8 days', // @translate
                        '30d' => 'older than 30 days', // @translate
                    ],
                    'default' => '8d',
                ],
            ],
        ],
        'backup_database' => [
            'label' => 'Backup database', // @translate
            'module' => 'EasyAdmin',
            'task_type' => 'job',
            'frequencies' => ['daily', 'weekly', 'monthly'],
            'default_frequency' => 'weekly',
            'params' => [
                'format' => [
                    'label' => 'Format', // @translate
                    'options' => [
                        'compressed' => 'Compressed (gzip)', // @translate
                        'plain' => 'Plain SQL', // @translate
                    ],
                    'default' => 'compressed',
                ],
            ],
        ],
        'backup_files' => [
            'label' => 'Backup files (modules, themes, config)', // @translate
            'module' => 'EasyAdmin',
            'task_type' => 'job',
            'frequencies' => ['weekly', 'monthly'],
            'default_frequency' => 'weekly',
            'params' => [
                'scope' => [
                    'label' => 'Scope', // @translate
                    'options' => [
                        'full' => 'Full backup (core, modules, themes, config)', // @translate
                        'config' => 'Configuration only', // @translate
                    ],
                    'default' => 'full',
                ],
            ],
        ],
    ],
];

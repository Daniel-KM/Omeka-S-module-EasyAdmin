<?php declare(strict_types=1);

namespace EasyAdmin;

return [
    'service_manager' => [
        'invokables' => [
            Mvc\MvcListeners::class => Mvc\MvcListeners::class,
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
            /** Deprecated: use form group. */
            'formNote' => Form\View\Helper\FormNote::class,
            'lastBrowsePage' => View\Helper\LastBrowsePage::class,
        ],
        'factories' => [
            'nextResource' => Service\ViewHelper\NextResourceFactory::class,
            'previousNext' => Service\ViewHelper\PreviousNextFactory::class,
            'previousResource' => Service\ViewHelper\PreviousResourceFactory::class,
        ],
        'delegators' => [
            'Laminas\Form\View\Helper\FormElement' => [
                __NAMESPACE__ => Service\Delegator\FormElementDelegatorFactory::class,
            ],
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\Note::class => Form\Element\Note::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\AddonsForm::class => Service\Form\AddonsFormFactory::class,
            Form\CheckAndFixForm::class => Service\Form\CheckAndFixFormFactory::class,
            // TODO Remove fix when integrated in Omeka S (fix #2236).
            'Omeka\Form\AssetEditForm' => Service\Form\FormWithEventManagerFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'EasyAdmin\Controller\Admin\Addons' => Controller\Admin\AddonsController::class,
            'EasyAdmin\Controller\Admin\CheckAndFix' => Controller\Admin\CheckAndFixController::class,
            'Omeka\Controller\Admin\Maintenance' => Controller\Admin\MaintenanceController::class,
        ],
        'factories' => [
            // Class is not used as key, since it's set dynamically by sub-route
            // and it should be available in acl (so alias is mapped later).
            'EasyAdmin\Controller\Upload' => Service\Controller\UploadControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'easyAdminAddons' => Service\ControllerPlugin\AddonsFactory::class,
        ],
    ],
    // TODO Remove these routes and use main admin/default.
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
                            'upload' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/upload[/:action]',
                                    'constraints' => [
                                        'action' => 'index|files',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'EasyAdmin\Controller',
                                        'controller' => 'Upload',
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
        'AdminModule' => [
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
                        'label' => 'Install addons', // @translate
                        'route' => 'admin/easy-admin/default',
                        'controller' => 'addons',
                        'class' => 'o-icon- fa-puzzle-piece',
                    ],
                    [
                        // Not "Upload" because translation is not good here.
                        'label' => 'Upload files', // @translate
                        'route' => 'admin/easy-admin/upload',
                        'action' => 'files',
                        'resource' => 'EasyAdmin\Controller\Upload',
                        'class' => 'o-icon- fa-cloud-upload-alt',
                    ],
                ],
            ],
        ],
        'EasyAdmin' => [
            [
                'label' => 'Checks and fixes', // @translate
                'route' => 'admin/easy-admin/default',
                'controller' => 'check-and-fix',
            ],
            [
                'label' => 'Install addons', // @translate
                'route' => 'admin/easy-admin/default',
                'controller' => 'addons',
            ],
            [
                // Not "Upload" because translation is not good here.
                'label' => 'Upload files', // @translate
                'route' => 'admin/easy-admin/upload',
                'action' => 'files',
                'resource' => 'EasyAdmin\Controller\Upload',
            ],
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
        'settings' => [
            'easyadmin_interface' => [
                'resource_public_view',
                // 'resource_previous_next',
            ],
            'easyadmin_local_path' => OMEKA_PATH . '/files/preload',
            'easyadmin_allow_empty_files' => false,
            'easyadmin_addon_notify_version_inactive' => true,
            'easyadmin_addon_notify_version_dev' => false,
            // Disable content lock by default.
            'easyadmin_content_lock' => false,
            // 86400 seconds = 24 hours. 14400 = 4 hours.
            'easyadmin_content_lock_duration' => 14400,
            'easyadmin_maintenance_mode' => '',
            'easyadmin_maintenance_text' => 'This site is down for maintenance. Please contact the site administrator for more information.', // @translate
        ],
    ],
];

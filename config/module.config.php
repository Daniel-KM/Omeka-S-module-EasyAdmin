<?php declare(strict_types=1);

namespace EasyAdmin;

return [
    'service_manager' => [
        'invokables' => [
            Mvc\MvcListeners::class => Mvc\MvcListeners::class,
        ],
        'factories' => [
            'Omeka\File\TempFileFactory' => Service\File\TempFileFactoryFactory::class,
            'Omeka\File\Validator' => Service\File\ValidatorFactory::class,
        ],
    ],
    'listeners' => [
        Mvc\MvcListeners::class,
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
            'defaultSite' => Service\ViewHelper\DefaultSiteFactory::class,
            'nextResource' => Service\ViewHelper\NextResourceFactory::class,
            'previousNext' => Service\ViewHelper\PreviousNextFactory::class,
            'previousResource' => Service\ViewHelper\PreviousResourceFactory::class,
        ],
        'delegators' => [
            'Laminas\Form\View\Helper\FormElement' => [
               Service\Delegator\FormElementDelegatorFactory::class,
            ],
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\Note::class => Form\Element\Note::class,
            Form\Element\OptionalMultiCheckbox::class => Form\Element\OptionalMultiCheckbox::class,
            Form\Element\OptionalRadio::class => Form\Element\OptionalRadio::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\AddonsForm::class => Service\Form\AddonsFormFactory::class,
            Form\CheckAndFixForm::class => Service\Form\CheckAndFixFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'EasyAdmin\Controller\Addons' => Controller\AddonsController::class,
            'EasyAdmin\Controller\CheckAndFix' => Controller\CheckAndFixController::class,
            'Omeka\Controller\Maintenance' => Controller\MaintenanceController::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'easyAdminAddons' => Service\ControllerPlugin\AddonsFactory::class,
            'specifyMediaType' => Service\ControllerPlugin\SpecifyMediaTypeFactory::class,
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
                                '__NAMESPACE__' => 'EasyAdmin\Controller',
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
                ],
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
            // Disable
            'easyadmin_content_lock' => false,
            // 86400 seconds = 24 hours. 14400 = 4 hours.
            'easyadmin_content_lock_duration' => 14400,
            'easyadmin_maintenance_mode' => '',
            'easyadmin_maintenance_text' => 'This site is down for maintenance. Please contact the site administrator for more information.', // @translate
        ],
    ],
];

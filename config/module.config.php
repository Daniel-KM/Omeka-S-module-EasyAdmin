<?php declare(strict_types=1);

namespace EasyAdmin;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'formNote' => Form\View\Helper\FormNote::class,
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
        ],
        'factories' => [
            Form\JobsForm::class => Service\Form\JobsFormFactory::class,
            Form\UploadForm::class => Service\Form\UploadFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'EasyInstall\Controller\Admin\Index' => Controller\Admin\IndexController::class,
            'EasyAdmin\Controller\Job' => Controller\JobController::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'easyInstallAddons' => Service\ControllerPlugin\AddonsFactory::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'easy-admin' => [
                'label' => 'Checks and fixes', // @translate
                'route' => 'admin/easy-admin',
                'controller' => 'job',
                'resource' => 'EasyAdmin\Controller\Job',
                'class' => 'o-icon-jobs',
            ],
            [
                'label' => 'Easy Install',
                'route' => 'admin/easy-install',
                'resource' => 'Omeka\Controller\Admin\Module',
                'privilege' => 'browse',
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'easy-admin' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            // TODO The default route may be modified later.
                            'route' => '/easy-admin',
                            'defaults' => [
                                '__NAMESPACE__' => 'EasyAdmin\Controller',
                                '__ADMIN__' => true,
                                'controller' => 'Job',
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
                                        'controller' => 'job',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                    'defaults' => [
                                        'controller' => 'Job',
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'easy-install' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/easy-install',
                            'defaults' => [
                                '__NAMESPACE__' => 'EasyInstall\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
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
    // Keep empty config for automatic management.
    'easyadmin' => [
    ],
];

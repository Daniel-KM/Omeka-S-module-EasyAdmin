<?php declare(strict_types=1);

namespace EasyAdmin;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\JobsForm::class => Service\Form\JobsFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'EasyAdmin\Controller\Job' => Controller\JobController::class,
        ],
    ],
    // TODO Merge bulk navigation and route with module BulkImport (require a main page?).
    'navigation' => [
        'AdminModule' => [
            'easy-admin' => [
                'label' => 'Job manager', // @translate
                'route' => 'admin/easy-admin',
                'controller' => 'job',
                'resource' => 'EasyAdmin\Controller\Job',
                'class' => 'o-icon-jobs',
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
                            'route' => '/easy-admin/job',
                            'defaults' => [
                                '__NAMESPACE__' => 'EasyAdmin\Controller',
                                '__ADMIN__' => true,
                                'controller' => 'Job',
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

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
            Form\CheckForm::class => Service\Form\CheckFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'EasyAdmin\Controller\Check' => Controller\CheckController::class,
        ],
    ],
    // TODO Merge bulk navigation and route with module BulkImport (require a main page?).
    'navigation' => [
        'AdminModule' => [
            'easy-admin' => [
                'label' => 'Bulk Check', // @translate
                'route' => 'admin/bulk-check',
                'controller' => 'bulk-check',
                'resource' => 'EasyAdmin\Controller\Check',
                'class' => 'o-icon-jobs',
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'bulk-check' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/bulk-check',
                            'defaults' => [
                                '__NAMESPACE__' => 'EasyAdmin\Controller',
                                '__ADMIN__' => true,
                                'controller' => 'Check',
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

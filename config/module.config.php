<?php declare(strict_types=1);

namespace EasyAdmin;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'controller_map' => [
            Controller\Admin\BulkCheckController::class => 'bulk/admin/check',
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\BulkCheckForm::class => Service\Form\BulkCheckFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'BulkCheck\Controller\Admin\BulkCheck' => Controller\Admin\BulkCheckController::class,
        ],
    ],
    // TODO Merge bulk navigation and route with module BulkImport (require a main page?).
    'navigation' => [
        'AdminModule' => [
            'bulk-check' => [
                'label' => 'Bulk Check', // @translate
                'route' => 'admin/bulk-check',
                'controller' => 'bulk-check',
                'resource' => 'BulkCheck\Controller\Admin\BulkCheck',
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
                                '__NAMESPACE__' => 'BulkCheck\Controller\Admin',
                                '__ADMIN__' => true,
                                'controller' => 'BulkCheck',
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

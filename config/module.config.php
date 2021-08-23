<?php declare(strict_types=1);
namespace EasyInstall;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\UploadForm::class => Service\Form\UploadFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'EasyInstall\Controller\Admin\Index' => Controller\Admin\IndexController::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'easyInstallAddons' => Service\ControllerPlugin\AddonsFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
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
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Easy Install',
                'route' => 'admin/easy-install',
                'resource' => 'Omeka\Controller\Admin\Module',
                'privilege' => 'browse',
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
];

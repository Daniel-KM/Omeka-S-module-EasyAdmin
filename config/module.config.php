<?php
namespace EasyInstall;

return [
    'controllers' => [
        'invokables' => [
            'EasyInstall\Controller\Admin\Index' => Controller\Admin\IndexController::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'easyInstallAddons' => Mvc\Controller\Plugin\Addons::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
    'form_elements' => [
        'factories' => [
            'EasyInstall\Form\UploadForm' => Service\Form\UploadFormFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'easy-install' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/easy-install',
                            'defaults' => [
                                '__NAMESPACE__' => 'EasyInstall\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
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
            ],
        ],
    ],
];

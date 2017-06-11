<?php
namespace EasyInstall;

return [
    'controllers' => [
        'invokables' => [
            'EasyInstall\Controller\Index' => Controller\Admin\IndexController::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'easyInstallAddons' => Mvc\Controller\Plugin\Addons::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            OMEKA_PATH . '/modules/EasyInstall/view',
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
                    'easyinstall' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/easyinstall',
                            'defaults' => [
                                '__NAMESPACE__' => 'EasyInstall\Controller',
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
                'route' => 'admin/easyinstall',
                'resource' => Controller\Admin\IndexController::class,
            ],
        ],
    ],
];

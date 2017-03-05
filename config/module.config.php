<?php

return [
    'controllers' => [
        'invokables' => [
            'EasyInstall\Controller\Index' => 'EasyInstall\Controller\IndexController',
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'easyInstallAddons' => 'EasyInstall\Mvc\Controller\Plugin\Addons',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            OMEKA_PATH . '/modules/EasyInstall/view',
        ],
    ],
    'form_elements' => [
        'factories' => [
            'EasyInstall\Form\UploadForm' => 'EasyInstall\Service\Form\UploadFormFactory',
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
                'resource' => 'EasyInstall\Controller\Index',
            ],
        ],
    ],
];

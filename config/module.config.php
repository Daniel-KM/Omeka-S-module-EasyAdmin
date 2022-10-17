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

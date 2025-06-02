<?php declare(strict_types=1);

/*
 * Copyright 2017-2025 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace EasyAdmin;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Laminas\Session\Container;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Module\AbstractModule;

/**
 * Easy Admin.
 *
 * @copyright Daniel Berthereau, 2017-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'Common',
    ];

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var \Omeka\Settings\Settings $settings */
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        if ($settings->get('easyadmin_display_exception')) {
            ini_set('display_errors', '1');
        }

        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Any user who can create an item can use bulk upload.
        // Admins are not included because they have the rights by default.
        $roles = [
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
        ];

        $acl
            ->allow(
                $roles,
                ['EasyAdmin\Controller\Upload'],
                [
                    'index',
                ]
            )
            ->allow(
                $roles,
                ['EasyAdmin\Controller\Admin\FileManager'],
                [
                    'browse',
                    'delete',
                    'delete-confirm',
                ]
            )
        ;

        if ($settings->get('easyadmin_rights_reviewer_delete_all')) {
            $acl
                ->allow(
                    'reviewer',
                    [
                        \Omeka\Entity\Item::class,
                        \Omeka\Entity\ItemSet::class,
                        \Omeka\Entity\Media::class,
                        \Omeka\Entity\Asset::class,
                    ],
                    [
                        'delete',
                    ]
                )
            ;
        }
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.67')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.67'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $js = __DIR__ . '/asset/vendor/flow.js/flow.min.js';
        if (!file_exists($js)) {
            $message = new PsrMessage(
                'The libraries should be installed. See module’s installation documentation.' // @translate
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }

        $this->installDirs();

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $settings = $services->get('Omeka\Settings');
        $settings->set('easyadmin_local_path', $settings->get('bulkimport_local_path') ?: $basePath . '/preload');
        $settings->set('easyadmin_allow_empty_files', (bool) $settings->get('bulkimport_allow_empty_files'));
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $settings->set('easyadmin_cron_tasks', ['session_8']);

        $this->postInstallAuto();
    }

    protected function installDirs(): void
    {
        // Don't use PsrMessage during install.
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $translator = $services->get('MvcTranslator');

        // Automatic upgrade from module Bulk Check.
        $result = null;
        $bulkCheckPath = $basePath . '/bulk_check';
        if (file_exists($bulkCheckPath) && is_dir($bulkCheckPath)) {
            $result = rename($bulkCheckPath, $basePath . '/check');
            if (!$result) {
                $message = new PsrMessage(
                    'Upgrading module BulkCheck: Unable to rename directory "files/bulk_check" into "files/check". Trying to create it.' // @translate
                );
                $messenger->addWarning($message);
            }
        }

        if (!$result && !$this->checkDestinationDir($basePath . '/check')) {
            $message = new PsrMessage(
                'The directory "{dir}" is not writeable.', // @translate
                ['dir' => $basePath]
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }

        if (!$this->checkDestinationDir($basePath . '/backup')) {
            $message = new PsrMessage(
                'The directory "{dir}" is not writeable.', // @translate
                ['dir' => $basePath]
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }

        if (!$this->checkDestinationDir($basePath . '/import')) {
            $message = new PsrMessage(
                'The directory "{dir}" is not writeable.', // @translate
                ['dir' => $basePath]
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }

        /** @var \Omeka\Module\Manager $moduleManager */
        $modules = [
            'BulkCheck',
            'EasyInstall',
            'Maintenance',
        ];
        $connection = $services->get('Omeka\Connection');
        $moduleManager = $services->get('Omeka\ModuleManager');
        foreach ($modules as $moduleName) {
            $module = $moduleManager->getModule($moduleName);
            $sql = 'DELETE FROM `module` WHERE `id` = "' . $moduleName . '";';
            $connection->executeStatement($sql);
            $sql = 'DELETE FROM `setting` WHERE `id` LIKE "' . strtolower($moduleName) . '\\_%";';
            $connection->executeStatement($sql);
            $sql = 'DELETE FROM `site_setting` WHERE `id` LIKE "' . strtolower($moduleName) . '\\_%";';
            $connection->executeStatement($sql);
            if ($module) {
                $message = new PsrMessage(
                    'The module "{module}" was upgraded by module "{module_2}" and uninstalled.', // @translate
                    ['module' => $moduleName, 'module_2' => 'Easy Admin']
                );
                $messenger->addWarning($message);
            }
        }
    }

    protected function preUninstall(): void
    {
        if (!empty($_POST['remove-dir-check'])) {
            $config = $this->getServiceLocator()->get('Config');
            $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            $this->rmDir($basePath . '/check');
        }
    }

    public function warnUninstall(Event $event): void
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() != __NAMESPACE__) {
            return;
        }

        $services = $this->getServiceLocator();
        $t = $services->get('MvcTranslator');
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $html = '<p>';
        $html .= '<strong>';
        $html .= $t->translate('WARNING:'); // @translate
        $html .= '</strong>';
        $html .= '</p>';

        $html .= '<p>';
        $html .= new PsrMessage(
            'All stored files from checks and fixes, if any, will be removed from folder "{folder}".', // @translate
            ['folder' => $basePath . '/check']
        );
        $html .= '</p>';

        $html .= '<label><input name="remove-dir-check" type="checkbox" form="confirmform">';
        $html .= $t->translate('Remove directory "files/check"'); // @translate
        $html .= '</label>';

        echo $html;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // TODO What is the better event to handle a cron?
        $sharedEventManager->attach(
            '*',
            'view.layout',
            [$this, 'handleCron']
        );

        // Manage buttons in admin resources.
        // TODO Use Omeka S v4.1 event "view.show.page_actions" (but none for browse!).
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.layout',
            [$this, 'handleViewLayoutResource']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.layout',
            [$this, 'handleViewLayoutResource']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.layout',
            [$this, 'handleViewLayoutResource']
        );

        // Manage resources templates and classes on resource form.
        $sharedEventManager->attach(
            \Omeka\Form\ResourceForm::class,
            'form.add_elements',
            [$this, 'handleResourceForm']
        );

        // Manage default and public site links in right sidebar.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.details',
            [$this, 'handleViewDetailsResource']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.details',
            [$this, 'handleViewDetailsResource']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.details',
            [$this, 'handleViewDetailsResource']
        );

        // Manage previous/next resource on items/browse and AdvancedSearch.
        // TODO Manage item sets and media for search?
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.browse.before',
            [$this, 'handleViewBrowse']
        );
        $sharedEventManager->attach(
            \AdvancedSearch\Controller\SearchController::class,
            'view.layout',
            [$this, 'handleViewBrowse']
        );

        // Add js for the item add/edit pages to manage ingester "bulk_upload".
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.add.before',
            [$this, 'addHeadersAdmin']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.before',
            [$this, 'addHeadersAdmin']
        );
        // Manage the special media ingester "bulk_upload".
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.hydrate.pre',
            [$this, 'handleItemApiHydratePre']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'handleAfterSaveItem'],
            -10
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'handleAfterSaveItem'],
            -10
        );

        // Optimize asset.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\AssetAdapter::class,
            'api.create.post',
            [$this, 'handleAfterSaveAsset']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\AssetAdapter::class,
            'api.update.post',
            [$this, 'handleAfterSaveAsset']
        );
        $sharedEventManager->attach(
            \Omeka\Form\AssetEditForm::class,
            'form.add_elements',
            [$this, 'handleFormAsset']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

        // Check last version of modules.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.browse.after',
            [$this, 'checkAddonVersions']
        );

        $sharedEventManager->attach(
            \Omeka\Media\Ingester\Manager::class,
            'service.registered_names',
            [$this, 'handleMediaIngesterRegisteredNames']
        );

        // Display a warn before uninstalling.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );
    }

    public function handleCron(Event $event): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $tasks = $settings->get('easyadmin_cron_tasks', []);
        if (!count($tasks)) {
            return;
        }

        $lastCron = (int) $settings->get('easyadmin_cron_last');
        $time = time();
        if ($lastCron + 86400 > $time) {
            return;
        }

        $settings->set('easyadmin_cron_last', $time);

        // Short tasks.

        foreach ($tasks as $task) switch ($task) {
            case 'session_2':
            case 'session_8':
            case 'session_40':
            case 'session_100':
                $days = (int) substr($task, 8);
                // If there is no index, use a job.
                /** @var \Doctrine\DBAL\Connection $connection */
                $connection = $services->get('Omeka\Connection');
                $result = $connection->executeQuery('SHOW INDEX FROM `session` WHERE `column_name` = "modified";');
                if ($result->fetchOne()) {
                    $sql = 'DELETE `session` FROM `session` WHERE `modified` < :time;';
                    $connection->executeStatement(
                        $sql,
                        ['time' => $time - $days * 86400],
                        ['time' => \Doctrine\DBAL\ParameterType::INTEGER]
                    );
                } else {
                    $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
                    $dispatcher->dispatch(\EasyAdmin\Job\DbSession::class, [
                        'days' => $days,
                        'quick' => true,
                    ]);
                }
                break;

            default:
                break;
        }
    }

    public function handleViewLayoutResource(Event $event): void
    {
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $params = $view->params()->fromRoute();
        $action = $params['action'] ?? 'browse';
        if ($action !== 'show' && $action !== 'browse') {
            return;
        }

        $controller = $params['__CONTROLLER__'] ?? $params['controller'] ?? '';
        $controllersToResourceTypes = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'media' => 'media',
            'Omeka\Controller\Admin\Item' => 'items',
            'Omeka\Controller\Admin\ItemSet' => 'item_sets',
            'Omeka\Controller\Admin\Media' => 'media',
        ];
        if (!isset($controllersToResourceTypes[$controller])) {
            return;
        }

        if ($action === 'browse') {
            $this->handleViewLayoutResourceBrowse($view, $controllersToResourceTypes[$controller]);
        } else {
            // The resource is not available in the main view.
            $id = isset($params['id']) ? (int) $params['id'] : 0;
            if ($id) {
                $this->handleViewLayoutResourceShow($view, $controllersToResourceTypes[$controller], $id);
            }
        }
    }

    protected function handleViewLayoutResourceBrowse(PhpRenderer $view, string $resourceType): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Settings\Settings $settings
         * @var \Common\Stdlib\EasyMeta $easyMeta
         * @var \Doctrine\DBAL\Connection $connection
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $templateIds = $settings->get('easyadmin_quick_template');
        $classTerms = $settings->get('easyadmin_quick_class');
        if (!$templateIds && !$classTerms) {
            return;
        }

        $acl = $services->get('Omeka\Acl');
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $adapter = $services->get('Omeka\ApiAdapterManager')->get($resourceType);
        if (!$user || !$acl->userIsAllowed(get_class($adapter), 'create')) {
            return;
        }

        $api = $services->get('Omeka\ApiManager');
        $easyMeta = $services->get('Common\EasyMeta');

        $templateLabels = [];
        if ($templateIds) {
            $hasAll = in_array('all', $templateIds);
            $templateLabels = $easyMeta->resourceTemplateLabels($hasAll ? [] : $templateIds);
        }

        // Search all templates with specified classes.
        $classIds = $easyMeta->resourceClassIds($classTerms);
        $templateClassLabels = [];
        if ($classIds) {
            // The api allows to sort by class, but not to search, so
            // the argument is added via an event.
            // TODO Resource templates cannot be searched by classes, so use sql.
            // The module AdvancedResourceTemplate allows to suggest
            // multiple classes by template.
            if ($this->isModuleActive('AdvancedResourceTemplate')) {
                /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation[] $templates */
                $templates = $api->search('resource_templates', [])->getContent();
                foreach ($templates as $template) {
                    $useForResources = $template->dataValue('use_for_resources');
                    if (!$useForResources || in_array($resourceType, $useForResources)) {
                        $suggestedClasses = $template->dataValue('suggested_resource_class_ids') ?: [];
                        if ($ids = array_intersect($suggestedClasses, $classIds)) {
                            $templateClassLabels[$template->id()] = ['label' => $template->label(), 'resource_class_id' => reset($ids)];
                        }
                    }
                }
            } else {
                $connection = $services->get('Omeka\Connection');
                $qb = $connection->createQueryBuilder();
                $qb
                    ->select('id', 'label', 'resource_class_id')
                    ->distinct()
                    ->from('resource_template', 'resource_template')
                    ->where('resource_template.`resource_class_id` IN (:ids)')
                    ->setParameter('ids', $classIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
                    ->addOrderBy('resource_template`.`label`', 'asc')
                ;
                $templateClassLabels = $qb->execute()->fetchAllAssociative();
            }
        }

        if (!$templateLabels && !$templateClassLabels) {
            return;
        }

        // TODO Check if module AdvancedResourceTemplate is active, and filter templates.

        $plugins = $view->getHelperPluginManager();
        $url = $plugins->get('url');
        $translate = $plugins->get('translate');
        $hyperlink = $plugins->get('hyperlink');

        $vars = $view->vars();
        $html = $vars->offsetGet('content');

        $mainLabels = [
            'items' => 'Add new item',
            'item_sets' => 'Add new item set',
            'media' => 'Add new media',
        ];

        $buttons = [];
        $buttons[] = $hyperlink($translate('Any'), $url(null, ['action' => 'add'], true), ['class' => 'link']);
        foreach ($templateLabels as $id => $label) {
            $buttons[$id] = $hyperlink($translate($label), $url(null, ['action' => 'add'], ['query' => ['resource_template_id' => $id]], true), ['class' => 'link']);
        }

        foreach ($templateClassLabels as $id => $data) {
            $buttons[$id] = $hyperlink($translate($data['label']), $url(null, ['action' => 'add'], ['query' => ['resource_template_id' => $id, 'resource_class_id' => $data['resource_class_id']]], true), ['class' => 'link']);
        }

        if (count($buttons) > 1) {
            // TODO Use a real button instead of an anchor.
            $stringButtons = '<li>' . implode("</li>\n<li>", $buttons) . "</li>\n";
            $stringExpand = $translate('Expand');
            $stringAdd = $translate($mainLabels[$resourceType]);
            // Styles adapted from the module Scripto.
            $html = preg_replace(
                '~<div id="page-actions">(.*?)</div>~s',
                <<<HTML
                    <style>
                        #page-action-menu .expand::after {
                            padding-left: 4px;
                        }
                        #page-action-menu {
                            display:inline-block;
                            position:relative
                        }
                        #page-action-menu ul {
                            /* display:none; */
                            list-style:none;
                            border:1px solid #dfdfdf;
                            background-color:#fff;
                            border-radius:3px;
                            text-align:left;
                            padding:0;
                            position:relative;
                            box-shadow:0 0 5px #dfdfdf;
                            position:absolute;
                            right:0;
                            width:auto;
                            white-space:nowrap;
                            margin:12px 0
                        }
                        #page-action-menu ul::before {
                            content:"";
                            position:absolute;
                            bottom:calc(100% - 1px);
                            right:12px;
                            width:0;
                            height:0;
                            border-bottom:12px solid #fff;
                            border-left:6px solid transparent;
                            border-right:6px solid transparent
                        }
                        #page-action-menu ul::after {
                            content:"";
                            position:absolute;
                            bottom:calc(100% - 1px);
                            right:11px;
                            width:0;
                            height:0;
                            border-bottom:14px solid #dfdfdf;
                            border-left:7px solid transparent;
                            border-right:7px solid transparent;
                            z-index:-1
                        }
                        #page-action-menu ul a,
                        #page-action-menu ul .inactive {
                            padding:6px 12px 5px;
                            display:block;
                            position:relative
                        }
                        #page-action-menu ul .inactive {
                            color:#dfdfdf
                        }
                    </style>
                    <div id="page-actions">
                        <div id="page-action-menu">
                            <a href="#" class="expand button" aria-label="$stringExpand>">$stringAdd</a>
                            <ul class="collapsible">
                                $stringButtons
                            </ul>
                        </div>
                    </div>
                    HTML,
                $html,
                1
            );
        }

        $vars->offsetSet('content', $html);
    }

    protected function handleViewLayoutResourceShow(PhpRenderer $view, string $resourceType, int $id): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $interface = $settings->get('easyadmin_interface') ?: [];
        $buttonPublicView = in_array('resource_public_view', $interface);
        $buttonPreviousNext = in_array('resource_previous_next', $interface);
        if (!$buttonPublicView && !$buttonPreviousNext) {
            return;
        }

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        // Normally, the current resource should be present in vars.
        $vars = $view->vars();
        if ($vars->offsetExists('resource')) {
            $resource = $vars->offsetGet('resource');
        } else {
            try {
                $resource = $services->get('Omeka\ApiManager')->read($resourceType, ['id' => $id], ['initialize' => false, 'finalize' => false])->getContent();
            } catch (\Exception $e) {
                return;
            }
        }

        $html = $vars->offsetGet('content');

        // Add public view only when there is no site, since they are added in
        // Omeka S v4.1 for items. But only for items: so for consistent ux, set
        // the button in the new place for all resources.
        if ($buttonPublicView) {
            $isOldOmeka = version_compare(\Omeka\Module::VERSION, '4.1', '<');
            $skip = !$isOldOmeka && $resourceType === 'items' && count($resource->sites());
            if (!$skip) {
                $plugins = $services->get('ViewHelperManager');
                $translate = $plugins->get('translate');
                $htmlSites = $this->prepareSitesResource($resource);
                if ($resourceType === 'item_sets' && count($resource->sites())) {
                    $translated = $translate('Sites');
                    $htmlRegex = <<<REGEX
                        <div class="meta-group[\w _-]*">\s*<h4>$translated</h4>.*</div>\s*<div class="meta-group
                        REGEX;
                    $html = preg_replace('~' . $htmlRegex . '~s', $htmlSites . '<div class="meta-group', $html, 1);
                } else {
                    $translated = $resourceType === 'item_sets' ? $translate('Items') : $translate('Created');
                    $htmlPost = <<<REGEX
                        <div class="meta-group">
                                <h4>$translated</h4>
                        REGEX;
                    $htmlRegex = <<<REGEX
                        <div class="meta-group">\s*<h4>$translated</h4>
                        REGEX;
                    $html = preg_replace('~' . $htmlRegex . '~s', $htmlSites . $htmlPost, $html, 1);
                }
            }
        }

        if ($buttonPreviousNext) {
            /** @see \EasyAdmin\View\Helper\PreviousNext */
            $linkBrowseView = $view->previousNext($resource, [
                'source_query' => 'session',
                'back' => true,
            ]);
            if ($linkBrowseView) {
                $html = preg_replace(
                    '~<div id="page-actions">(.*?)</div>~s',
                    '<div id="page-actions">$1 ' . $linkBrowseView . '</div>',
                    $html,
                    1
                );
            }
        }

        $vars->offsetSet('content', $html);
    }

    public function handleViewDetailsResource(Event $event): void
    {
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $event->getParam('entity');

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $interface = $settings->get('easyadmin_interface') ?: [];
        $buttonPublicView = in_array('resource_public_view', $interface);
        if ($buttonPublicView) {
            // TODO Fix for item sets.
            $isOldOmeka = version_compare(\Omeka\Module::VERSION, '4.1', '<');
            $skip = !$isOldOmeka
                && $resource->resourceName() === 'items'
                && count($resource->sites());
            if (!$skip) {
                $htmlSites = $this->prepareSitesResource($resource);
                echo $htmlSites;
            }
        }

        if ($resource instanceof \Omeka\Api\Representation\MediaRepresentation) {
            $view = $event->getTarget();
            echo $view->partial('omeka/admin/media/show-details-renderer', [
                'media' => $resource,
                'resource' => $resource,
            ]);
        }
    }

    protected function prepareSitesResource(AbstractResourceEntityRepresentation $resource): string
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ViewHelperManager');

        $defaultSite = $plugins->get('defaultSite');
        $defaultSiteSlug = $defaultSite('slug');

        $resourceType = $resource->resourceName();
        $res = $resourceType === 'media' ? $resource->item() : $resource;

        $sites = $res->sites();
        $hasSites = count($sites);
        if (!$hasSites && $defaultSiteSlug) {
            $sites = [$defaultSite()];
        } elseif (!count($sites)) {
            return '';
        }

        // See application/view/omeka/admin/item/show.phtml.

        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $url = $plugins->get('url');
        $translate = $plugins->get('translate');
        $hyperlink = $plugins->get('hyperlink');
        $easyMeta = $services->get('Common\EasyMeta');

        $controller = $resource->getControllerName();
        $resourceId = $resource->id();

        $htmlSites = '';

        $htmlSite = <<<'HTML'
            <div class="value">
                __SITE_TITLE__
                __RESOURCE_LINK__
            </div>
            HTML . "\n";
        foreach ($sites as $site) {
            $siteTitle = $site->title();
            $externalLinkText = new PsrMessage(
                'View this {resource_type} in "{site}"', // @translate
                ['resource_type' => $easyMeta->resourceLabel($resourceType), 'site' => $siteTitle]
            );
            $replace = [
                '__SITE_TITLE__' => $site->link($siteTitle) . ($hasSites ? '' : ' ' . $translate('[not in site]')), // @translate
                '__RESOURCE_LINK__' => $hyperlink(
                    '',
                    $url('site/resource-id', ['site-slug' => $site->slug(), 'controller' => $controller, 'id' => $resourceId]),
                    ['class' => 'o-icon-external', 'target' => '_blank', 'aria-label' => $externalLinkText, 'title' => $externalLinkText]
                ),
            ];
            $htmlSites .= str_replace(array_keys($replace), array_values($replace), $htmlSite);
        }

        // The class item-sites is kept for css.
        $translatedSites = $translate('Sites'); // @translate
        $html = <<<HTML
            <div class="meta-group $controller-sites item-sites">
                <h4>$translatedSites</h4>
                $htmlSites
            </div>
           HTML . "\n";
        return $html;
    }

    /**
     * Copy in:
     * @see \BlockPlus\Module::handleViewBrowse()
     * @see \EasyAdmin\Module::handleViewBrowse()
     */
    public function handleViewBrowse(Event $event): void
    {
        $session = new Container('EasyAdmin');
        if (!isset($session->lastBrowsePage)) {
            $session->lastBrowsePage = [];
            $session->lastQuery = [];
        }
        $params = $event->getTarget()->params();
        // $ui = $params->fromRoute('__SITE__') ? 'public' : 'admin';
        $ui = 'admin';
        // Why not use $this->getServiceLocator()->get('Request')->getServer()->get('REQUEST_URI')?
        $session->lastBrowsePage[$ui]['items'] = $_SERVER['REQUEST_URI'];
        // Store the processed query too for quicker process later and because
        // the controller may modify it (default sort order).
        $session->lastQuery[$ui]['items'] = $params->fromQuery();
    }

    public function addHeadersAdmin(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/bulk-upload.css', 'EasyAdmin'));
        $view->headScript()
            ->appendFile($assetUrl('vendor/flow.js/flow.min.js', 'EasyAdmin'), 'text/javascript', ['defer' => 'defer'])
            ->appendFile($assetUrl('js/bulk-upload.js', 'EasyAdmin'), 'text/javascript', ['defer' => 'defer']);
    }

    public function handleResourceForm(Event $event): void
    {
        /**
         * @var \Omeka\Mvc\Status $status
         * @var \Omeka\Form\ResourceForm $form
         */
        $services = $this->getServiceLocator();

        $status = $services->get('Omeka\Status');
        if (!$status->isAdminRequest()) {
            return;
        }

        /**
         * Set resource template and class ids from query for a new resource.
         * Else, it will be the user setting one.
         *
         * This feature is managed by modules Advanced Resource Template and
         * Easy Admin, but in a different way (internally or via settings).
         *
         * This feature requires to override file appliction/view/common/resource-fields.phtml.
         *
         * @see \AdvancedResourceTemplate\Module::handleResourceForm()
         * @see \EasyAdmin\Module::handleResourceForm()
         */

        if ($status->getRouteParam('action') === 'add') {
            $form = $event->getTarget();
            $params = $services->get('ControllerPluginManager')->get('Params');
            $resourceTemplateId = $params->fromQuery('resource_template_id');
            if ($resourceTemplateId && $form->has('o:resource_template[o:id]')) {
                /** @var \Omeka\Form\Element\ResourceTemplateSelect $templateSelect */
                $templateSelect = $form->get('o:resource_template[o:id]');
                if (in_array($resourceTemplateId, array_keys($templateSelect->getValueOptions()))) {
                    $templateSelect->setValue($resourceTemplateId);
                }
            }
            $resourceClassId = $params->fromQuery('resource_class_id');
            if ($resourceClassId && $form->has('o:resource_class[o:id]')) {
                /** @var \Omeka\Form\Element\ResourceClassSelect $templateSelect */
                $classSelect = $form->get('o:resource_class[o:id]');
                if (in_array($resourceClassId, array_keys($classSelect->getValueOptions()))) {
                    $classSelect->setValue($resourceClassId);
                }
            }
        }
    }

    public function handleItemApiHydratePre(Event $event): void
    {
        $services = $this->getServiceLocator();
        $tempDir = $services->get('Config')['temp_dir'] ?: sys_get_temp_dir();
        $tempDir = rtrim($tempDir, '/\\');

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();

        if (empty($data['o:media'])) {
            return;
        }

        // Remove removed files.
        $filesData = $data['filesData'] ?? [];
        if (empty($filesData['file'])) {
            return;
        }

        foreach ($filesData['file'] ?? [] as $key => $fileData) {
            $filesData['file'][$key] = json_decode($fileData, true) ?: [];
        }

        /**
         * @var \Omeka\Stdlib\ErrorStore $errorStore
         * @var \Omeka\File\TempFileFactory $tempFileFactory
         * @var \Omeka\File\Validator $validator
         */
        $errorStore = $event->getParam('errorStore');
        $settings = $services->get('Omeka\Settings');
        $validator = $services->get(\Omeka\File\Validator::class);
        $tempFileFactory = $services->get(\Omeka\File\TempFileFactory::class);
        $validateFile = (bool) $settings->get('disable_file_validation', false);
        $allowEmptyFiles = (bool) $settings->get('easyadmin_allow_empty_files', false);

        $uploadErrorCodes = [
            UPLOAD_ERR_OK => 'File successfuly uploaded.', // @translate
            UPLOAD_ERR_INI_SIZE => 'The total of file sizes exceeds the the server limit directive.', // @translate
            UPLOAD_ERR_FORM_SIZE => 'The file size exceeds the specified limit.', // @translate
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.', // @translate
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.', // @translate
            UPLOAD_ERR_NO_TMP_DIR => 'The temporary folder to store the file is missing.', // @translate
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.', // @translate
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.', // @translate
        ];

        $newDataMedias = [];
        foreach ($data['o:media'] as $dataMedia) {
            $newDataMedias[] = $dataMedia;

            if (empty($dataMedia['o:ingester'])
                || $dataMedia['o:ingester'] !== 'bulk_upload'
            ) {
                continue;
            }

            $index = $dataMedia['file_index'] ?? null;
            if (is_null($index) || !isset($filesData['file'][$index])) {
                $errorStore->addError('upload', 'There is no uploaded files.'); // @translate
                continue;
            }

            if (empty($filesData['file'][$index])) {
                $errorStore->addError('upload', 'There is no uploaded files.'); // @translate
                continue;
            }

            // Convert the media to a list of media for the item hydration.
            // Check errors first to indicate issues to user early.
            $listFiles = [];
            $hasError = false;
            foreach ($filesData['file'][$index] as $subIndex => $fileData) {
                // The user selected "allow partial upload", so no data for this
                // index.
                if (empty($fileData)) {
                    continue;
                }
                // Fix strict type issues in case of an issue on a file.
                $fileData['name'] ??= '';
                $fileData['tmp_name'] ??= '';
                if (!empty($fileData['error'])) {
                    $errorStore->addError('upload', new PsrMessage(
                        'File #{index} "{filename}" has an error: {error}.',  // @translate
                        ['index' => ++$subIndex, 'filename' => $fileData['name'], 'error' => $uploadErrorCodes[$fileData['error']]]
                    ));
                    $hasError = true;
                    continue;
                } elseif (substr($fileData['name'], 0, 1) === '.') {
                    $errorStore->addError('upload', new PsrMessage(
                        'File #{index} "{filename}" must not start with a ".".', // @translate
                        ['index' => ++$subIndex, 'filename' => $fileData['name']]
                    ));
                    $hasError = true;
                    continue;
                } elseif (!preg_match('/^[^\/\\\\{}$?!<>]+$/', $fileData['name'])) {
                    $errorStore->addError('upload', new PsrMessage(
                        'File #{index} "{filename}" must not contain a reserved character.', // @translate
                        ['index' => ++$subIndex, 'filename' => $fileData['name']]
                    ));
                    $hasError = true;
                    continue;
                } elseif (!preg_match('/^[^\/\\\\{}$?!<>]+$/', $fileData['tmp_name'])) {
                    $errorStore->addError('upload', new PsrMessage(
                        'File #{index} temp name "{filename}" must not contain a reserved character.', // @translate
                        ['index' => ++$subIndex, 'filename' => $fileData['tmp_name']]
                    ));
                    $hasError = true;
                    continue;
                } elseif (empty($fileData['size'])) {
                    if ($validateFile && !$allowEmptyFiles) {
                        $errorStore->addError('upload', new PsrMessage(
                            'File #{index} "{filename}" is an empty file.', // @translate
                            ['index' => ++$subIndex, 'filename' => $fileData['name']]
                        ));
                        $hasError = true;
                        continue;
                    }
                } else {
                    // Don't use uploader::upload(), because the file would be
                    // renamed, so use temp file validator directly.
                    // Don't check media-type directly, because it should manage
                    // derivative media-types ("application/tei+xml", etc.) that
                    // may not be extracted by system.
                    $tempFile = $tempFileFactory->build();
                    $tempFile->setSourceName($fileData['name']);
                    $tempFile->setTempPath($tempDir . DIRECTORY_SEPARATOR . $fileData['tmp_name']);
                    if (!$validator->validate($tempFile, $errorStore)) {
                        // Errors are already stored.
                        continue;
                    }
                }
                $listFiles[] = $fileData;
            }
            if ($hasError) {
                continue;
            }

            // Remove the added media directory from list of media.
            array_pop($newDataMedias);
            foreach ($listFiles as $index => $fileData) {
                $dataMedia['ingest_file_data'] = $fileData;
                $newDataMedias[] = $dataMedia;
            }
        }

        $data['o:media'] = $newDataMedias;
        $request->setContent($data);
    }

    public function handleAfterSaveItem(Event $event): void
    {
        // Prepare thumbnailing only if needed.
        $needThumbnailing = false;

        /**
         * @var \Omeka\Entity\Item $item
         * @var \Omeka\Entity\Media $media
         */
        $item = $event->getParam('response')->getContent();
        foreach ($item->getMedia() as $media) {
            if (!$media->hasThumbnails()
                && $media->getMediaType()
                && $media->getIngester() === 'bulk_upload'
            ) {
                $needThumbnailing = true;
                break;
            }
        }

        if (!$needThumbnailing) {
            return;
        }

        $services = $this->getServiceLocator();

        // Create the thumbnails for the media ingested with "bulk_upload" via a
        // job to avoid the 30 seconds issue with numerous files.
        $args = [
            'item_id' => $item->getId(),
            'ingester' => 'bulk_upload',
            'only_missing' => true,
        ];
        // Of course, it is useless for a background job.
        // FIXME Use a plugin, not a fake job. Or strategy "sync", but there is a doctrine exception on owner of the job.
        // $strategy = $this->isBackgroundProcess() ? $services->get(\Omeka\Job\DispatchStrategy\Synchronous::class) : null;
        $strategy = null;
        if ($this->isBackgroundProcess()) {
            $job = new \Omeka\Entity\Job();
            $job->setPid(null);
            $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
            $job->setClass(\EasyAdmin\Job\FileDerivativeBulkUpload::class);
            $job->setArgs($args);
            $job->setOwner($services->get('Omeka\AuthenticationService')->getIdentity());
            $job->setStarted(new \DateTime('now'));
            $jobClass = new \EasyAdmin\Job\FileDerivativeBulkUpload($job, $services);
            $jobClass->perform();
        } else {
            /** @var \Omeka\Job\Dispatcher $dispatcher */
            $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
            $dispatcher->dispatch(\EasyAdmin\Job\FileDerivativeBulkUpload::class, $args, $strategy);
        }
    }

    public function handleAfterSaveAsset(Event $event): void
    {
        /**
         * @var \Omeka\Entity\Asset $asset
         * @var \Omeka\Api\Request $request
         */
        $request = $event->getParam('request');

        $optimize = $request->getValue('optimize');
        if (!$optimize) {
            return;
        }

        $fileData = $request->getFileData();
        if (!empty($fileData['file']['error'])) {
            return;
        }

        $asset = $event->getParam('response')->getContent();
        if (!$asset) {
            return;
        }

        // Process the optimization.

        /**
         * @var \Laminas\Log\Logger $logger
         * @var \Omeka\File\TempFile $tempFile
         * @var \Omeka\File\Downloader $downloader
         * @var \Omeka\File\Store\StoreInterface $store
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Api\Adapter\AssetAdapter $assetAdapter
         * @var \Omeka\File\ThumbnailManager $thumbnailManager
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         * @var \Omeka\Api\Representation\AssetRepresentation $assetRepresentation
         */
        $services = $this->getServiceLocator();
        $store = $services->get('Omeka\File\Store');
        $logger = $services->get('Omeka\Logger');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $downloader = $services->get('Omeka\File\Downloader');
        $assetAdapter = $services->get('Omeka\ApiAdapterManager')->get('assets');
        $thumbnailManager = $services->get('Omeka\File\ThumbnailManager');
        $assetRepresentation = $assetAdapter->getRepresentation($asset);

        // Get asset as a temp file.
        $assetUrl = $assetRepresentation->assetUrl();
        $errorStore = new \Omeka\Stdlib\ErrorStore;
        $tempFile = $downloader->download($assetUrl, $errorStore);
        if (!$tempFile) {
            $logger->err(new PsrMessage(
                'An error occurred when fetching asset "{asset_filename}" (#{asset_id}): {errors}', // @translate
                ['asset_filename' => $asset->getName(), 'asset_id' => $asset->getId(), 'errors' => $errorStore->getErrors()]
            ));
            $messenger->addErrors($errorStore->getErrors());
            return;
        }

        $thumbnailer = $thumbnailManager->buildThumbnailer();
        $thumbnailer->setSource($tempFile);
        // SetOptions() is required to set the path for ImageMagick when used.
        $thumbnailer->setOptions([]);
        try {
            $newFilePath = $thumbnailer->create('default', 800);
        } catch (\Exception $e) {
            $message = new PsrMessage(
                'An error occurred when optimizing asset "{asset_filename}" (#{asset_id}): {error}', // @translate
                ['asset_filename' => $asset->getName(), 'asset_id' => $asset->getId(), 'error' => $e->getMessage()]
            );
            $logger->err($message->getMessage(), $message->getContext());
            $messenger->addError($message);
            $tempFile->delete();
            return;
        }

        // Check if the new size is really smaller: minimum 90% to keep quality.
        $originalFileSize = $tempFile->getSize();
        $newFileSize = filesize($newFilePath);
        $gain = 100 - ($newFileSize * 100 / $originalFileSize);

        // Remove the downloaded file.
        $tempFile->delete();

        if ($gain < 10) {
            unlink($newFilePath);
            return;
        }

        // Store the file with the new extension.
        try {
            $tempFile->setStorageId($asset->getStorageId());
            $tempFile->setTempPath($newFilePath);
            $tempFile->store('asset', 'jpg');
        } catch (\Omeka\File\Exception\RuntimeException $e) {
            $message = new PsrMessage(
                'An error occurred when storing asset "{asset_filename}" (#{asset_id}): {error}', // @translate
                ['asset_filename' => $asset->getName(), 'asset_id' => $asset->getId(), 'error' => $e->getMessage()]
            );
            $logger->err($message->getMessage(), $message->getContext());
            $messenger->addError($message);
            $tempFile->delete();
            return;
        }

        // Delete the temporary new file.
        $tempFile->delete();

        // Remove the original file if the extension was different.
        if ($asset->getExtension() !== 'jpg') {
            $store->delete('asset/' . $asset->getFilename());
        }

        // Update the asset in database with the new media type and extension.
        if ($asset->getExtension() !== 'jpg'
            || $asset->getMediaType() !== 'image/jpeg'
        ) {
            // Update the original name with the new extension only when there
            // was one.
            $assetName = $asset->getName();
            $assetExtension = $asset->getExtension();
            if (!strcasecmp((string) pathinfo($assetName, PATHINFO_EXTENSION), $assetExtension)) {
                $asset->setName(mb_substr($assetName, 0, - mb_strlen($assetExtension) - 1) . '.jpg');
            }
            // Use entity manager to avoid a loop of events.
            $asset->setExtension('jpg');
            $asset->setMediaType('image/jpeg');
            $entityManager = $services->get('Omeka\EntityManager');
            $entityManager->persist($asset);
            $entityManager->flush();
        }

        $message = new PsrMessage(
            'The asset "{asset_filename}" (#{asset_id}) has been successfully optimized by {percent}%, from {size_1} to {size_2} bytes.', // @translate
            ['asset_filename' => $asset->getName(), 'asset_id' => $asset->getId(), 'percent' => (int) $gain, 'size_1' => $originalFileSize, 'size_2' => $newFileSize]
        );
        $logger->notice($message->getMessage(), $message->getContext());
        $messenger->addSuccess($message);
    }

    public function handleFormAsset(Event $event): void
    {
        /** @var \Omeka\Form\AssetEditForm $form */
        $form = $event->getTarget();
        $element = new \Laminas\Form\Element\Checkbox();
        $element
            ->setName('optimize')
            ->setLabel('Optimize size for web (may degrade quality)'); // @translate
        $form->add($element);
    }

    /**
     * Check if the current process is a background one.
     *
     * The library to get status manages only admin, site or api requests.
     * A background process is none of them.
     */
    protected function isBackgroundProcess(): bool
    {
        // Warning: there is a matched route ("site") for backend processes.
        /** @var \Omeka\Mvc\Status $status */
        $status = $this->getServiceLocator()->get('Omeka\Status');
        return !$status->isApiRequest()
            && !$status->isAdminRequest()
            && !$status->isSiteRequest()
            && (!method_exists($status, 'isKeyauthRequest') || !$status->isKeyauthRequest());
    }

    public function checkAddonVersions(Event $event): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('version_notifications')) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();

        $json = [];
        foreach ($view->modules ?? [] as $module) {
            if ($module->getState() !== \Omeka\Module\Manager::STATE_ACTIVE) {
                $moduleId = $module->getId();
                $moduleName = $module->getName();
                $moduleVersion = $module->getIni('version') ?: $module->getDb('version');
                if ($moduleId && $moduleName && $moduleVersion) {
                    $json[$moduleName] = [
                        'id' => $moduleId,
                        'version' => $moduleVersion,
                    ];
                }
            }
        }

        $style = '.version-notification.new-version-is-dev { background-color:#fff6e6; color: orange; }'
            . '.version-notification.new-version-is-dev::after { content: " (dev)"; color: red; }';

        $view->headStyle()
            ->appendStyle($style);

        $notifyVersionInactive = (bool) $settings->get('easyadmin_addon_notify_version_inactive');
        $notifyVersionDev = (bool) $settings->get('easyadmin_addon_notify_version_dev');

        $script = 'const notifyVersionInactive = ' . json_encode($notifyVersionInactive) . ";\n"
            . 'const notifyVersionDev = ' . json_encode($notifyVersionDev) . ";\n"
            // Keep original translation.
            . 'const msgNewVersion = ' . json_encode(trim(sprintf($view->translate('A new version of this module is available. %s'), ''))) . ';'
            . 'const unmanagedAddons = ' . json_encode($json, 320) . ";\n";

        $view->headScript()
            ->appendScript($script)
            ->appendFile($view->assetUrl('js/check-versions.js', 'EasyAdmin'), 'text/javascript', ['defer' => 'defer']);
    }

    /**
     * Avoid to display ingester in item edit, because it's an internal one.
     */
    public function handleMediaIngesterRegisteredNames(Event $event): void
    {
        $names = $event->getParam('registered_names');
        $key = array_search('bulk_uploaded', $names);
        unset($names[$key]);
        $event->setParam('registered_names', $names);
    }
}

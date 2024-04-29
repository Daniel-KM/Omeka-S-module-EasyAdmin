<?php declare(strict_types=1);

/*
 * Copyright 2017-2024 Daniel Berthereau
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

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use DateTime;
use EasyAdmin\Entity\ContentLock;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Session\Container;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Module\AbstractModule;

/**
 * Easy Admin
 *
 * @copyright Daniel Berthereau, 2017-2024
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'Common',
    ];

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.55')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.55'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $this->installDir();
    }

    protected function installDir(): void
    {
        // Don't use PsrMessage during install.
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        // Automatic upgrade from module Bulk Check.
        $result = null;
        $bulkCheckPath = $basePath . '/bulk_check';
        if (file_exists($bulkCheckPath) && is_dir($bulkCheckPath)) {
            $result = rename($bulkCheckPath, $basePath . '/check');
            if (!$result) {
                $message = new \Omeka\Stdlib\Message(
                    'Upgrading module BulkCheck: Unable to rename directory "files/bulk_check" into "files/check". Trying to create it.' // @translate
                );
                $messenger->addWarning($message);
            }
        }

        if (!$result && !$this->checkDestinationDir($basePath . '/check')) {
            $message = new \Omeka\Stdlib\Message(
                'The directory "%s" is not writeable.', // @translate
                $basePath
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        if (!$this->checkDestinationDir($basePath . '/backup')) {
            $message = new \Omeka\Stdlib\Message(
                'The directory "%s" is not writeable.', // @translate
                $basePath
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
                $message = new \Omeka\Stdlib\Message(
                    'The module "%s" was upgraded by module "%s" and uninstalled.', // @translate
                    $moduleName, 'Easy Admin'
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
        $html .= sprintf(
            $t->translate('All stored files from checks and fixes, if any, will be removed from folder "{folder}".'), // @translate
            $basePath . '/check'
        );
        $html .= '</p>';

        $html .= '<label><input name="remove-dir-check" type="checkbox" form="confirmform">';
        $html .= $t->translate('Remove directory "files/check"'); // @translate
        $html .= '</label>';

        echo $html;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Manage buttons in admin resources.
        // TODO Use Omeka S v4.1 event "view.show.page_actions".
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

        // Manage previous/next resource. Require module EasyAdmin.
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

        // Content locking in admin board.
        // It is useless in public board, because there is the moderation.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        // The check for content locking can be done via `api.hydrate.pre` or
        // `api.update.pre`, that is bypassable in code.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );

        // There is no good event for deletion. So either js on layout, either
        // view.details and js, eiher override confirm form and/or delete confirm
        // to add elements or add a trigger in delete-confirm-details.
        // Here, view details + inline js to avoid to load a js in many views.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.details',
            [$this, 'contentLockingOnDeleteConfirm']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.details',
            [$this, 'contentLockingOnDeleteConfirm']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.details',
            [$this, 'contentLockingOnDeleteConfirm']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
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

        // Display a warn before uninstalling.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );
    }

    public function handleViewLayoutResource(Event $event): void
    {
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        $params = $view->params()->fromRoute();
        $action = $params['action'] ?? 'browse';
        if ($action !== 'show') {
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

        // The resource is not available in the main view.
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if (!$id) {
            return;
        }

        $resourceType = $controllersToResourceTypes[$controller];
        $controller = array_search($controller, $controllersToResourceTypes);

        $services = $this->getServiceLocator();

        $settings = $services->get('Omeka\Settings');
        $interface = $settings->get('easyadmin_interface') ?: [];
        $publicView = in_array('resource_public_view', $interface);
        $previousNext = in_array('resource_previous_next', $interface);
        if (!$publicView && !$previousNext) {
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
        if ($publicView) {
            $isOldOmeka = version_compare(\Omeka\Module::VERSION, '4.1', '<');
            $skip = !$isOldOmeka && $resourceType === 'items' && count($resource->sites());
            if (!$skip) {
                $plugins = $services->get('ViewHelperManager');
                $translate = $plugins->get('translate');
                $htmlSites = $this->prepareSitesResource($resource);
                if ($resourceType === 'item_sets' && count($resource->sites())) {
                    $translated = $translate('Sites');
                    $htmlRegex = <<<REGEX
<div class="meta-group">\s*<h4>$translated</h4>.*</div>\s*<div class="meta-group">
REGEX;
                    $html = preg_replace('~' . $htmlRegex . '~s', $htmlSites . '<div class="meta-group">', $html);
                } else {
                    $translated = $resourceType === 'item_sets' ? $translate('Items') : $translate('Created');
                    $htmlPost = <<<HTML
    <div class="meta-group">
        <h4>$translated</h4>

HTML;
                    $html = str_replace($htmlPost, $htmlSites . $htmlPost, $html);
                }
            }
        }

        if ($previousNext) {
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
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $interface = $settings->get('easyadmin_interface') ?: [];
        $publicView = in_array('resource_public_view', $interface);
        if ($publicView) {
            /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
            $resource = $event->getParam('entity');
            // TODO Fix for item sets.
            $isOldOmeka = version_compare(\Omeka\Module::VERSION, '4.1', '<');
            $skip = !$isOldOmeka && $resource->resourceName() === 'items' && count($resource->sites());
            if (!$skip) {
                $htmlSites = $this->prepareSitesResource($resource);
                echo $htmlSites;
            }
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
        }
        if (!count($sites)) {
            return '';
        }

        // See application/view/omeka/admin/item/show.phtml.

        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $url = $plugins->get('url');
        $translate = $plugins->get('translate');
        $hyperlink = $plugins->get('hyperlink');
        $easyMeta = $services->get('EasyMeta');

        $controller = $resource->getControllerName();
        $resourceId = $resource->id();

        $htmlSites = '';

        $htmlSite = <<<'HTML'
        <div class="value">
            __SITE_TITLE__
            __RESOURCE_LINK__
        </div>

HTML;
        foreach ($sites as $site) {
            $siteTitle = $site->title();
            $externalLinkText = new PsrMessage(
                'View this {resource_type} in "{site}"', // @translate
                ['resource_type' => $easyMeta->resourceLabel($resourceType), 'site' => $siteTitle]
            );
            $replace = [
                '__SITE_TITLE__' => $site->link($siteTitle) . ($hasSites ? '' : $translate('[not in site]')), // @translate
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

HTML;
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

    public function contentLockingOnEdit(Event $event): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Api\Representation\AbstractEntityRepresentation $resource
         * @var \Omeka\Entity\User $user
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \EasyAdmin\Entity\ContentLock $contentLock
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */
        $view = $event->getTarget();
        $resource = $view->resource;
        if (!$resource) {
            return;
        }

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('easyadmin_content_lock')) {
            return;
        }

        // This mapping is needed because the api name is not available in the
        // representation.
        $resourceNames = [
            \Omeka\Api\Representation\ItemRepresentation::class => 'items',
            \Omeka\Api\Representation\ItemSetRepresentation::class => 'item_sets',
            \Omeka\Api\Representation\MediaRepresentation::class => 'media',
            'o:Item' => 'items',
            'o:ItemSet' => 'item_sets',
            'o:Media' => 'media',
        ];

        $entityId = $resource->id();
        $entityName = $resourceNames[get_class($resource)] ?? $resourceNames[$resource->getJsonLdType()] ?? null;
        if (!$entityId || !$entityName) {
            return;
        }

        $this->removeExpiredContentLocks();

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);

        if (!$contentLock) {
            $contentLock = new ContentLock($entityId, $entityName);
            $contentLock
                ->setUser($user)
                ->setCreated(new DateTIme('now'));
            $entityManager->persist($contentLock);
            try {
                // Flush is needed because the event does not run it.
                $entityManager->flush($contentLock);
                return;
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                // Sometime a duplicate entry occurs even if checked just above.
                try {
                    $entityManager->remove($contentLock);
                    $contentLock = $entityManager->getRepository(ContentLock::class)
                        ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
                } catch (\Exception $e) {
                    return;
                }
            } catch (\Exception $e) {
                // No content lock when there is an issue.
                $entityManager->remove($contentLock);
                return;
            }
        }

        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $contentLockUser = $contentLock->getUser();
        $isCurrentUser = $user->getId() === $contentLockUser->getId();

        if ($isCurrentUser) {
            $message = new PsrMessage(
                'You edit already this resource somewhere since {date}.', // @translate
                ['date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short')]
            );
            $messenger->addWarning($message);
            // Refresh the content lock: this is a new edition or a
            // submitted one. So the previous lock should be removed and a
            // a new one created, but it's simpler to override first one.
            $contentLock->setCreated(new DateTIme('now'));
            $entityManager->persist($contentLock);
            $entityManager->flush($contentLock);
            return;
        }

        $controllerNames = [
            'items' => 'item',
            'item_sets' => 'item-set',
            'media' => 'media',
        ];

        // TODO Add rights to bypass.

        /** @var \Laminas\Http\PhpEnvironment\Request $request */
        $request = $services->get('Application')->getMvcEvent()->getRequest();

        $isPost = $request->isPost();

        $message = new PsrMessage(
            'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
            [
                'user_name' => $contentLockUser->getName(),
                'date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );
        $messenger->add($isPost ? \Omeka\Mvc\Controller\Plugin\Messenger::ERROR : \Omeka\Mvc\Controller\Plugin\Messenger::WARNING, $message);

        $html = <<<'HTML'
<label><input type="checkbox" name="bypass_content_lock" class="bypass-content-lock" value="1" form="edit-{entity_name}"/>{message_bypass}</label>
<div class="easy-admin confirm-delete">
    <label><input type="checkbox" name="bypass_content_lock" class="bypass-content-lock" value="1" form="confirmform"/>{message_bypass}</label>
    <script>
        $(document).ready(function() {
            $('.easy-admin.confirm-delete').prependTo('#delete.sidebar #confirmform');
            const buttonPageAction = $('#page-actions button[type=submit]');
            const buttonSidebar = $('#delete.sidebar #confirmform input[name=submit]');
            buttonPageAction.prop('disabled', true);
            buttonSidebar.prop('disabled', true);
            $('.bypass-content-lock').on('change', function () {
                const button = $(this).parent().parent().hasClass('confirm-delete') ? buttonSidebar : buttonPageAction;
                button.prop('disabled', !$(this).is(':checked'));
            });
        });
    </script>
</div>
HTML;
        $message = $view->translate('Bypass the lock'); // @translate
        $message = new PsrMessage($html, ['entity_name' => $controllerNames[$entityName], 'message_bypass' => $message]);
        $message->setEscapeHtml(false);
        $messenger->add($isPost ? \Omeka\Mvc\Controller\Plugin\Messenger::ERROR : \Omeka\Mvc\Controller\Plugin\Messenger::WARNING, $message);
    }

    public function contentLockingOnDeleteConfirm(Event $event): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Api\Representation\AbstractEntityRepresentation $resource
         * @var \Omeka\Mvc\Status $status
         * @var \Omeka\Entity\User $user
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \EasyAdmin\Entity\ContentLock $contentLock
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */

        // In the view show-details, the action is not known.
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        $routeMatch = $status->getRouteMatch();
        if (!$status->isAdminRequest()
            || $routeMatch->getMatchedRouteName() !== 'admin/id'
            || $routeMatch->getParam('action') !== 'delete-confirm'
        ) {
            return;
        }

        $view = $event->getTarget();
        $resource = $view->resource;
        if (!$resource) {
            return;
        }

        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('easyadmin_content_lock')) {
            return;
        }

        // This mapping is needed because the api name is not available in the
        // representation.
        $resourceNames = [
            \Omeka\Api\Representation\ItemRepresentation::class => 'items',
            \Omeka\Api\Representation\ItemSetRepresentation::class => 'item_sets',
            \Omeka\Api\Representation\MediaRepresentation::class => 'media',
            'o:Item' => 'items',
            'o:ItemSet' => 'item_sets',
            'o:Media' => 'media',
        ];

        $entityId = $resource->id();
        $entityName = $resourceNames[get_class($resource)] ?? $resourceNames[$resource->getJsonLdType()] ?? null;
        if (!$entityId || !$entityName) {
            return;
        }

        $this->removeExpiredContentLocks();

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);

        // Don't create or refresh a content lock on confirm delete.
        if (!$contentLock) {
            return;
        }

        // TODO Add rights to bypass.

        $contentLockUser = $contentLock->getUser();
        $isCurrentUser = $user->getId() === $contentLockUser->getId();

        $html = <<<'HTML'
<div class="easy-admin confirm-delete">
    <p class="error">
        <strong>%1$s</strong>
        %2$s
    </p>
    %3$s
    <script>
        $(document).ready(function() {
            $('.easy-admin.confirm-delete').prependTo('#sidebar .sidebar-content #confirmform');
            if (!$('.easy-admin.confirm-delete .bypass-content-lock').length) {
                return;
            }
            const buttonSidebar = $('.sidebar #sidebar-confirm input[name=submit]');
            buttonSidebar.prop('disabled', true);
            $('.bypass-content-lock').on('change', function () {
                buttonSidebar.prop('disabled', !$(this).is(':checked'));
            });
        });
    </script>
</div>
HTML;

        $translator = $services->get('MvcTranslator');

        $messageWarn = new PsrMessage('Warning:'); // @translate
        if ($isCurrentUser) {
            $message = new PsrMessage(
                'You edit this resource somewhere since {date}.', // @translate
                ['date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short')]
            );
            $messageInput = new PsrMessage('');
        } else {
            $message = new PsrMessage(
                'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
                [
                    'user_name' => $contentLockUser->getName(),
                    'date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short'),
                ]
            );
            $messageInput = new PsrMessage('Bypass the lock'); // @translate
            $messageInput = new PsrMessage('<label><input type="checkbox" name="bypass_content_lock" class="bypass-content-lock" value="1" form="confirmform"/>{message_bypass}</label>', ['message_bypass' => $messageInput->setTranslator($translator)]);
        }

        echo sprintf($html, $messageWarn->setTranslator($translator), $message->setTranslator($translator), $messageInput->setTranslator($translator));
    }

    public function contentLockingOnSave(Event $event): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Entity\User $user
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \EasyAdmin\Entity\ContentLock $contentLock
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('easyadmin_content_lock')) {
            return;
        }

        $request = $event->getParam('request');

        $entityId = $request->getId();
        $entityName = $request->getResource();
        if (!$entityId || !$entityName) {
            return;
        }

        $this->removeExpiredContentLocks();

        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
        if (!$contentLock) {
            return;
        }

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $contentLockUser = $contentLock->getUser();
        if ($user->getId() === $contentLockUser->getId()) {
            // The content lock won't be removed in case of a validation
            // exception.
            $entityManager->remove($contentLock);
            return;
        }

        $i18n = $services->get('ViewHelperManager')->get('i18n');

        // When a lock is bypassed, keep it for the original user.
        if ($request->getValue('bypass_content_lock')) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $message = new PsrMessage(
                'The lock in place since {date} has been bypassed, but the user {user_name} can override it on save.', // @translate
                [
                    'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
                    'user_name' => $contentLockUser->getName(),
                ]
            );
            $messenger->addWarning($message);
            return;
        }

        // Keep the message for backend api process.
        $message = new PsrMessage(
            'User {user} (#{userid}) tried to save {resource_name} #{resource_id} edited by the user {user_name} (#{user_id}) since {date}.', // @translate
            [
                'user' => $user->getName(),
                'userid' => $user->getId(),
                'resource_name' => $entityName,
                'resource_id' => $entityId,
                'user_name' => $contentLockUser->getName(),
                'user_id' => $contentLockUser->getId(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );
        $services->get('Omeka\Logger')->err($message);

        $message = new PsrMessage(
            'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
            [
                'user_name' => $contentLockUser->getName(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );

        // Throw exception for frontend and backend.
        throw new \Omeka\Api\Exception\ValidationException((string) $message);
    }

    /**
     * Remove content lock on resource deletion.
     *
     * The primary key is not a join column, so old deleted record can remain.
     */
    public function contentLockingOnDelete(Event $event): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \EasyAdmin\Entity\ContentLock $contentLock
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        // Remove the content lock on delete even when the feature is disabled.
        $isContentLockEnabled = $settings->get('easyadmin_content_lock');

        $request = $event->getParam('request');

        $entityId = $request->getId();
        $entityName = $request->getResource();
        if (!$entityId || !$entityName) {
            return;
        }

        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
        if (!$contentLock) {
            return;
        }

        if (!$isContentLockEnabled) {
            // The content lock won't be removed in case of a validation
            // exception.
            $entityManager->remove($contentLock);
            return;
        }

        $i18n = $services->get('ViewHelperManager')->get('i18n');

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $contentLockUser = $contentLock->getUser();

        if ($user->getId() === $contentLockUser->getId()) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $message = new PsrMessage(
                'You removed the resource you are editing somewhere since {date}.', // @translate
                ['date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short')]
            );
            $messenger->addWarning($message);
            // The content lock won't be removed in case of a validation
            // exception.
            $entityManager->remove($contentLock);
            return;
        }

        // When lock is bypassed on delete, don't keep it for the user editing.

        // TODO Find a better way to check bypass content lock.
        if ($request->getValue('bypass_content_lock')
            || $request->getOption('bypass_content_lock')
            || !empty($_POST['bypass_content_lock'])
        ) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $message = new PsrMessage(
                'You removed a resource currently locked in edition by {user_name} since {date}.', // @translate
                [
                    'user_name' => $contentLockUser->getName(),
                    'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
                ]
            );
            $messenger->addWarning($message);
            // Will be flushed automatically in post.
            $entityManager->remove($contentLock);
            return;
        }

        // Keep the message for backend api process.
        $message = new PsrMessage(
            'User {user} (#{userid}) tried to delete {resource_name} #{resource_id} edited by the user {user_name} (#{user_id}) since {date}.', // @translate
            [
                'user' => $user->getName(),
                'userid' => $user->getId(),
                'resource_name' => $entityName,
                'resource_id' => $entityId,
                'user_name' => $contentLockUser->getName(),
                'user_id' => $contentLockUser->getId(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );
        $services->get('Omeka\Logger')->err($message);

        $message = new PsrMessage(
            'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
            [
                'user_name' => $contentLockUser->getName(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );

        // Throw exception for frontend and backend.
        // TODO Redirect to browse or show view instead of displaying the error.
        throw new \Omeka\Api\Exception\ValidationException((string) $message);
    }

    protected function removeExpiredContentLocks(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $duration = (int) $settings->get('easyadmin_content_lock_duration');
        if (!$duration) {
            return;
        }

        // Use connection, because entity manager won't remove values and will
        // cause complex flush process/sync.
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement(
            'DELETE FROM content_lock WHERE created < DATE_SUB(NOW(), INTERVAL :duration SECOND)',
            ['duration' => $duration]
        );
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
}

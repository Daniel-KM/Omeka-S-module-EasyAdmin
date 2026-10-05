<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use EasyAdmin\Form\AddonManageForm;
use EasyAdmin\Form\ModuleStateForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Module\Manager as OmekaModuleManager;

class ModuleController extends AbstractActionController
{
    /**
     * @var \Omeka\Module\Manager
     */
    protected $moduleManager;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    public function __construct(
        OmekaModuleManager $moduleManager,
        string $basePath,
        \Doctrine\ORM\EntityManager $entityManager
    ) {
        $this->moduleManager = $moduleManager;
        $this->basePath = $basePath;
        $this->entityManager = $entityManager;
    }

    public function indexAction()
    {
        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();

        // Get installed modules from ModuleManager.
        $installedModules = $this->moduleManager->getModules();
        uasort($installedModules, function ($a, $b) {
            return strcasecmp($a->getName(), $b->getName());
        });

        // Build the manage form for installed modules.
        $manageForm = $this->getForm(AddonManageForm::class);

        $request = $this->getRequest();
        if ($request->isPost()) {
            return $this->handlePost($addons, $manageForm);
        }

        // The catalogue requires up to five sequential external http requests
        // (omeka.org and github), so on the first load (empty cache) it is
        // fetched asynchronously: the page is rendered immediately without it,
        // then a background request (catalogue=1) re-renders the content. When
        // already cached, or on that async request, it is served synchronously.
        $isCatalogueRequest = $request->isXmlHttpRequest()
            && $this->params()->fromQuery('catalogue') === '1';
        $cataloguePending = false;
        if ($isCatalogueRequest || $addons->isCached()) {
            $catalogueAddons = $addons->getAddons();
            $addons->enrichWithLocalState($catalogueAddons);
            $selections = $addons->getSelections();
        } else {
            $catalogueAddons = [];
            $selections = [];
            $cataloguePending = true;
        }

        $omekaModules = $catalogueAddons['omekamodule'] ?? [];
        $webModules = $catalogueAddons['module'] ?? [];

        // Flag the compatibility with the current Omeka S, so the catalogue
        // install list can disable the incompatible addons.
        $flagCompatibility = function (array &$list) use ($addons): void {
            foreach ($list as &$addon) {
                $hasVersions = is_array($addon['versions'] ?? null) && $addon['versions'];
                $compatible = $hasVersions ? $addons->pickCompatibleVersion($addon) : null;
                // Without version metadata, the compatibility is unknown, so do
                // not block the install.
                $addon['is_compatible'] = !$hasVersions || $compatible !== null;
                $addon['compatible_version'] = $compatible['version'] ?? null;
                $latest = $hasVersions ? reset($addon['versions']) : [];
                $addon['required_constraint'] = $latest['omeka_version_constraint'] ?? '';
            }
        };
        $flagCompatibility($omekaModules);
        $flagCompatibility($webModules);

        // Url of the background request that fetches and renders the catalogue.
        $catalogueUrl = $this->url()->fromRoute(
            'admin/easy-admin/default',
            ['controller' => 'module', 'action' => 'index'],
            ['query' => ['catalogue' => '1'] + $this->params()->fromQuery()]
        );

        // Filter by state if requested.
        $state = $this->params()->fromQuery('state');

        // Form factory for inline state-change forms.
        $stateForm = function (string $action, string $moduleId) {
            $form = $this->getForm(ModuleStateForm::class);
            $form->setAttribute('action', $this->url()->fromRoute(
                'admin/easy-admin/default',
                ['controller' => 'module', 'action' => $action],
                ['query' => ['id' => $moduleId]]
            ));
            $form->get('id')->setValue($moduleId);
            return $form;
        };

        // Get curated selections.
        $selections = $addons->getSelections();

        // Build the install form for the sidebar.
        $installCatalogueForm = $this->getForm(ModuleStateForm::class);
        $installCatalogueForm->setAttribute('action', $this->url()->fromRoute(
            'admin/easy-admin/default',
            ['controller' => 'module', 'action' => 'install']
        ));
        $installCatalogueForm->setAttribute('method', 'post');

        // Build the refresh form (CSRF only).
        $refreshForm = $this->getForm(ModuleStateForm::class);
        $refreshForm->setAttribute('action', $this->url()->fromRoute(
            'admin/easy-admin/default',
            ['controller' => 'module', 'action' => 'refresh-catalogue']
        ));
        $refreshForm->setAttribute('method', 'post');

        $view = new ViewModel([
            'installedModules' => $installedModules,
            'omekaModules' => $omekaModules,
            'webModules' => $webModules,
            'manageForm' => $manageForm,
            'stateForm' => $stateForm,
            'filterState' => $state,
            'moduleManager' => $this->moduleManager,
            'selections' => $selections,
            'installCatalogueForm' => $installCatalogueForm,
            'refreshForm' => $refreshForm,
            'cataloguePending' => $cataloguePending,
            'catalogueUrl' => $catalogueUrl,
            // A module present in composer-addons/ may be overridden by a real
            // directory in modules/, that is managed here, so the full check is
            // needed, not only the presence in composer-addons/.
            'isComposerManaged' => fn (string $dir): bool => $addons->isComposerManaged([
                'type' => 'module',
                'dir' => $dir,
            ]),
        ]);
        $view->setTemplate('easy-admin/admin/module/browse');

        // The async catalogue request returns only the content, swapped into
        // the page by javascript, so render without the admin layout.
        if ($isCatalogueRequest) {
            $view->setTerminal(true);
        }

        return $view;
    }

    public function refreshCatalogueAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        // Just clear the cache and reload: the catalogue is fetched again
        // asynchronously on the next page render, so the button returns
        // immediately instead of blocking on external http requests.
        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addons->clearCache();

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function updateConfirmAction()
    {
        $id = $this->params()->fromQuery('id');
        if (!$id) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'module')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'Unknown module "{name}".', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $addon['installed_version'] = $addons
            ->getInstalledVersion($addon);

        $integrity = $addons->checkIntegrity($addon);

        $form = $this->getForm(ModuleStateForm::class);
        $form->setAttribute('method', 'post');
        $form->setAttribute('action', $this->url()->fromRoute(
            'admin/easy-admin/default',
            ['controller' => 'module', 'action' => 'update']
        ));
        $form->get('id')->setValue($addon['dir'] ?? '');

        $view = new ViewModel([
            'addon' => $addon,
            'integrity' => $integrity,
            'form' => $form,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/module/update-confirm'
        );
        return $view;
    }

    public function removeConfirmAction()
    {
        $id = $this->params()->fromQuery('id');
        if (!$id) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'module')
            ?: $addons->dataFromNamespace($id);

        $integrity = $addon
            ? $addons->checkIntegrity($addon)
            : ['status' => 'unknown'];

        $form = $this->getForm(ModuleStateForm::class);
        $form->setAttribute('method', 'post');
        $form->setAttribute('action', $this->url()->fromRoute(
            'admin/easy-admin/default',
            ['controller' => 'module', 'action' => 'remove']
        ));
        $form->get('id')->setValue($id);

        $view = new ViewModel([
            'addon' => $addon,
            'addonDir' => $id,
            'integrity' => $integrity,
            'form' => $form,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/module/remove-confirm'
        );
        return $view;
    }

    public function showDetailsAction()
    {
        $id = $this->params()->fromQuery('id');
        $module = $id
            ? $this->moduleManager->getModule($id) : null;

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $id
            ? ($addons->dataFromNamespace($id, 'module')
                ?: $addons->dataFromNamespace($id))
            : null;

        $integrity = null;
        if ($addon) {
            $integrity = $addons->checkIntegrity($addon);
        }

        $view = new ViewModel([
            'moduleId' => $id,
            'module' => $module,
            'addon' => $addon,
            'integrity' => $integrity,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/module/show-details'
        );
        return $view;
    }

    public function installModuleAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirectToBrowse('not_installed');
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirectToBrowse('not_installed');
        }

        try {
            $this->moduleManager->install($module);
            $this->messenger()->addSuccess(new PsrMessage(
                'Module "{name}" installed and activated.', // @translate
                ['name' => $id]
            ));
        } catch (\Throwable $e) {
            $this->addModuleErrorMessage($e, $id);
            return $this->redirectToBrowse('not_installed');
        }

        return $this->redirectToBrowse('not_installed');
    }

    public function uninstallAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        try {
            $this->moduleManager->uninstall($module);
            $this->messenger()->addSuccess(new PsrMessage(
                'Module "{name}" uninstalled.', // @translate
                ['name' => $id]
            ));
        } catch (\Throwable $e) {
            $this->messenger()->addError(new PsrMessage(
                'Error uninstalling "{name}": {error}', // @translate
                ['name' => $id, 'error' => $e->getMessage()]
            ));
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function updateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        if (!$id) {
            $this->messenger()->addError(
                'No module specified.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'module')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'Unknown module "{name}".', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        // The confirmation displays the installed and the new versions, so the
        // update is forced, even to reinstall the same version.
        $result = $addons->updateAddon($addon, true);
        if (!$result) {
            // updateAddon already added error details.
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        // Auto-upgrade if requested.
        $autoUpgrade = (bool) $this->params()->fromPost(
            'auto_upgrade'
        );
        if ($autoUpgrade) {
            $module = $this->moduleManager->getModule(
                $addon['dir']
            );
            if ($module) {
                // The ModuleManager cached module ini at bootstrap.
                // After file update, re-read the ini from disk.
                $iniPath = OMEKA_PATH . '/modules/'
                    . $addon['dir'] . '/config/module.ini';
                $freshIni = file_exists($iniPath)
                    ? parse_ini_file($iniPath) : [];
                if ($freshIni) {
                    $module->setIni($freshIni);
                }
                $newIniVersion = $module->getIni('version');
                $dbVersion = $module->getDb('version');
                $needsUpgrade = $dbVersion
                    && $newIniVersion
                    && version_compare(
                        $dbVersion, $newIniVersion, '<'
                    );
                if ($needsUpgrade) {
                    // Force the state so the manager accepts
                    // the upgrade call.
                    $module->setState(
                        OmekaModuleManager::STATE_NEEDS_UPGRADE
                    );
                    try {
                        $this->moduleManager->upgrade($module);
                        $this->messenger()->addSuccess(
                            new PsrMessage(
                                'Upgrade of module "{name}" finalized.', // @translate
                                ['name' => $addon['name']]
                            )
                        );
                    } catch (\Throwable $e) {
                        $this->messenger()->addError(
                            new PsrMessage(
                                'Error upgrading database for "{name}": {error}', // @translate
                                [
                                    'name' => $addon['name'],
                                    'error' => $e->getMessage(),
                                ]
                            )
                        );
                    }
                }
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function removeAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        if (!$id) {
            $this->messenger()->addError(
                'No module specified.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'module')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            // Module not in catalogue: build minimal addon data
            // for removal.
            $addon = [
                'type' => 'module',
                'name' => $id,
                'dir' => $id,
                'basename' => $id,
                'url' => '',
                'zip' => '',
                'version' => '',
                'dependencies' => [],
            ];
        }

        $addons->removeAddon($addon);

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function activateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirectToBrowse('not_active');
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirectToBrowse('not_active');
        }

        try {
            $this->moduleManager->activate($module);
            $this->messenger()->addSuccess(new PsrMessage(
                'Module "{name}" activated.', // @translate
                ['name' => $id]
            ));
        } catch (\Throwable $e) {
            $this->addModuleErrorMessage($e, $id);
            return $this->redirectToBrowse('not_active');
        }

        return $this->redirectToBrowse('not_active');
    }

    public function deactivateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirectToBrowse('active');
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirectToBrowse('active');
        }

        try {
            $this->moduleManager->deactivate($module);
            $this->messenger()->addSuccess(new PsrMessage(
                'Module "{name}" deactivated.', // @translate
                ['name' => $id]
            ));
        } catch (\Throwable $e) {
            $this->addModuleErrorMessage($e, $id);
            return $this->redirectToBrowse('active');
        }

        return $this->redirectToBrowse('active');
    }

    public function upgradeAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirectToBrowse('needs_upgrade');
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirectToBrowse('needs_upgrade');
        }

        try {
            $this->moduleManager->upgrade($module);
            $this->messenger()->addSuccess(new PsrMessage(
                'Upgrade of module "{name}" finalized.', // @translate
                ['name' => $id]
            ));
        } catch (\Throwable $e) {
            $this->addModuleErrorMessage($e, $id);
            return $this->redirectToBrowse('needs_upgrade');
        }

        return $this->redirectToBrowse('needs_upgrade');
    }

    /**
     * Set the version stored in the database to the version of the files.
     *
     * When files are rolled back without rolling back the database, the
     * database keeps a version greater than the one of the files, so Omeka
     * does not run any upgrade and the module stays in an inconsistent state.
     * This marks the real version, so the next update runs the upgrades from
     * it. The database schema and the data are not modified, so this is only
     * useful when the rolled back versions did not change them, or when they
     * were reverted by other means.
     */
    public function downgradeVersionAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirectToBrowse();
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirectToBrowse();
        }

        // The version of the database is read from the entity and not from the
        // module manager, whose data is a snapshot built at the beginning of
        // the request.
        $entity = $this->entityManager
            ->find(\Omeka\Entity\Module::class, $id);
        if (!$entity) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" is not installed.', // @translate
                ['name' => $id]
            ));
            return $this->redirectToBrowse();
        }

        $iniVersion = (string) $module->getIni('version');
        $dbVersion = (string) $entity->getVersion();
        if (!$iniVersion || !$dbVersion) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" has no version in the files or in the database.', // @translate
                ['name' => $id]
            ));
            return $this->redirectToBrowse();
        }

        if (version_compare($dbVersion, $iniVersion, '<=')) {
            $this->messenger()->addWarning(new PsrMessage(
                'The database of module "{name}" is already on version {version_db}, that is not greater than the version {version_ini} of the files.', // @translate
                ['name' => $id, 'version_db' => $dbVersion, 'version_ini' => $iniVersion]
            ));
            return $this->redirectToBrowse();
        }

        try {
            $entity->setVersion($iniVersion);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->addModuleErrorMessage($e, $id);
            return $this->redirectToBrowse();
        }

        $this->messenger()->addSuccess(new PsrMessage(
            'The version of module "{name}" in the database was set from {version_db} to {version_ini}, the version of the files. The database schema and the data were not modified.', // @translate
            ['name' => $id, 'version_db' => $dbVersion, 'version_ini' => $iniVersion]
        ));

        return $this->redirectToBrowse();
    }

    public function installAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();

        $urls = $this->params()->fromPost('module_urls', []);
        if (!is_array($urls) || !$urls) {
            $this->messenger()->addError(
                'No module selected.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        // Build the list of addons to install.
        $toInstall = [];
        foreach ($urls as $url) {
            $addon = $addons->dataFromUrl($url, 'module')
                ?: $addons->dataFromUrl($url, 'omekamodule');
            if (!$addon) {
                continue;
            }
            if ($addons->dirExists($addon)) {
                continue;
            }
            $toInstall[] = $addon;
        }

        if (!$toInstall) {
            $this->messenger()->addError(
                'No new module to install.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        // Use background job for multiple modules.
        if (count($toInstall) > 3) {
            $job = $this->jobDispatcher()->dispatch(
                \EasyAdmin\Job\ManageAddons::class,
                ['operation' => 'install', 'addons' => $toInstall]
            );
            $urlPlugin = $this->url();
            $message = new PsrMessage(
                'Installing {count} modules in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
                [
                    'count' => count($toInstall),
                    'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                    'job_id' => $job->getId(),
                    'link_end' => '</a>',
                    'link_log' => class_exists('Log\Module', false)
                        ? sprintf('<a href="%1$s">', htmlspecialchars($urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                        : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
                ]
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
        } else {
            foreach ($toInstall as $addon) {
                $addons->installAddon($addon);
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function integrityAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $fromSource = (bool) $this->params()->fromPost(
            'from_source'
        );

        if ($id) {
            // Single module.
            $addon = $addons->dataFromNamespace($id, 'module')
                ?: $addons->dataFromNamespace($id);
            if (!$addon) {
                $addon = [
                    'type' => 'module',
                    'name' => $id,
                    'dir' => $id,
                    'basename' => $id,
                    'url' => '',
                    'zip' => '',
                    'version' => '',
                    'dependencies' => [],
                ];
            }
            $report = [$id => $addons->checkIntegrity(
                $addon,
                $fromSource
            )];
        } else {
            // All installed modules.
            $report = [];
            $modules = $this->moduleManager->getModules();
            foreach ($modules as $moduleId => $module) {
                $addon = $addons->dataFromNamespace(
                    $moduleId,
                    'module'
                ) ?: $addons->dataFromNamespace($moduleId);
                if (!$addon) {
                    $addon = [
                        'type' => 'module',
                        'name' => $moduleId,
                        'dir' => $moduleId,
                        'basename' => $moduleId,
                        'url' => '',
                        'zip' => '',
                        'version' => '',
                        'dependencies' => [],
                    ];
                }
                $report[$moduleId] = $addons->checkIntegrity(
                    $addon,
                    $fromSource
                );
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module', 'action' => 'integrity-report'],
            ['query' => ['report' => json_encode($report)]]
        );
    }

    protected function redirectToBrowse(?string $state = null): \Laminas\Http\Response
    {
        $options = $state
            ? ['query' => ['state' => $state]]
            : [];
        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module'],
            $options
        );
    }

    protected function addModuleErrorMessage(\Throwable $e, ?string $id = null): void
    {
        if (!ini_get('display_errors')) {
            $message = new PsrMessage(
                'To learn how to see more detailed information about this error, see the Omeka S User Manual page on {link}retrieving error messages{link_end}.', // @translate
                ['link' => '<a href="https://omeka.org/s/docs/user-manual/errorLogging/">', 'link_end' => '</a>']
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addError($message);
        }
        $this->messenger()->addError(new PsrMessage(
            '{name}{class}: {message}', // @translate
            [
                'name' => $id ? sprintf('%s — ', $id) : '',
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]
        ));
    }

    public function integrityReportAction()
    {
        $reportJson = $this->params()->fromQuery('report', '{}');
        $report = json_decode($reportJson, true) ?: [];

        $view = new ViewModel([
            'report' => $report,
        ]);
        $view->setTemplate(
            'easy-admin/admin/module/integrity-report'
        );
        return $view;
    }

    protected function handlePost($addons, $manageForm): \Laminas\Http\Response
    {
        $post = $this->params()->fromPost();

        // Bulk operations.
        $action = $post['bulk_action'] ?? '';
        $selected = $post['modules'] ?? [];

        if ($action && $selected) {
            // Process the dependencies first for install, update and activate,
            // and the dependents first for remove and deactivate.
            $selected = $addons->sortByDependencies(
                $selected,
                in_array($action, ['remove', 'deactivate'], true)
            );

            // For large selections, dispatch as job.
            if (count($selected) > 3
                && in_array($action, ['update', 'upgrade', 'remove'])
            ) {
                $dispatcher = $this->jobDispatcher();
                $args = [
                    'operation' => $action,
                    'type' => 'module',
                    'addons' => $selected,
                    'options' => [
                        'auto_upgrade' => !empty(
                            $post['auto_upgrade']
                        ),
                    ],
                ];
                $job = $dispatcher->dispatch(
                    \EasyAdmin\Job\ManageAddons::class,
                    $args
                );
                $urlPlugin = $this->url();
                $message = new PsrMessage(
                    'Processing {action} in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
                    [
                        'action' => $action,
                        'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                        'job_id' => $job->getId(),
                        'link_end' => '</a>',
                        'link_log' => class_exists('Log\Module', false)
                            ? sprintf('<a href="%1$s">', htmlspecialchars($urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                            : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
                    ]
                );
                $message->setEscapeHtml(false);
                $this->messenger()->addSuccess($message);
                return $this->redirect()->toRoute(
                    'admin/easy-admin/default',
                    ['controller' => 'module']
                );
            }

            foreach ($selected as $moduleId) {
                switch ($action) {
                    case 'activate':
                        $module = $this->moduleManager->getModule(
                            $moduleId
                        );
                        if ($module) {
                            try {
                                $this->moduleManager->activate(
                                    $module
                                );
                            } catch (\Throwable $e) {
                                $this->messenger()->addError(
                                    $e->getMessage()
                                );
                            }
                        }
                        break;

                    case 'deactivate':
                        $module = $this->moduleManager->getModule(
                            $moduleId
                        );
                        if ($module) {
                            try {
                                $this->moduleManager->deactivate(
                                    $module
                                );
                            } catch (\Throwable $e) {
                                $this->messenger()->addError(
                                    $e->getMessage()
                                );
                            }
                        }
                        break;

                    case 'update':
                        $addon = $addons->dataFromNamespace(
                            $moduleId,
                            'module'
                        ) ?: $addons->dataFromNamespace($moduleId);
                        if ($addon) {
                            $addons->updateAddon($addon);
                        }
                        break;

                    case 'upgrade':
                        // Upgrade the database of a module whose files are
                        // already updated: this is not the download above.
                        $module = $this->moduleManager->getModule($moduleId);
                        if (!$module) {
                            break;
                        }
                        if ($module->getState() !== OmekaModuleManager::STATE_NEEDS_UPGRADE) {
                            $this->messenger()->addWarning(new PsrMessage(
                                'The module "{name}" does not need to be upgraded.', // @translate
                                ['name' => $moduleId]
                            ));
                            break;
                        }
                        try {
                            $this->moduleManager->upgrade($module);
                            $this->messenger()->addSuccess(new PsrMessage(
                                'Upgrade of module "{name}" finalized.', // @translate
                                ['name' => $moduleId]
                            ));
                        } catch (\Throwable $e) {
                            $this->addModuleErrorMessage($e, $moduleId);
                        }
                        break;

                    case 'remove':
                        $addon = $addons->dataFromNamespace(
                            $moduleId,
                            'module'
                        ) ?: $addons->dataFromNamespace($moduleId);
                        if (!$addon) {
                            $addon = [
                                'type' => 'module',
                                'name' => $moduleId,
                                'dir' => $moduleId,
                                'basename' => $moduleId,
                                'url' => '',
                                'zip' => '',
                                'version' => '',
                                'dependencies' => [],
                            ];
                        }
                        $addons->removeAddon($addon);
                        break;
                }
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }
}

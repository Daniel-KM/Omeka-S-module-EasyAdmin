<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use EasyAdmin\Form\ModuleStateForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class ThemeController extends AbstractActionController
{
    /**
     * @var string
     */
    protected $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function indexAction()
    {
        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();

        $catalogueAddons = $addons->getAddons();
        $addons->enrichWithLocalState($catalogueAddons);

        $omekaThemes = $catalogueAddons['omekatheme'] ?? [];
        $webThemes = $catalogueAddons['theme'] ?? [];

        // Scan both local and composer-addons theme directories.
        $localThemes = [];
        foreach ($this->getThemesDirs() as $themesDir) {
            $dirs = array_diff(
                scandir($themesDir) ?: [],
                ['.', '..']
            );
            foreach ($dirs as $dir) {
                // Local themes/ takes precedence.
                if (isset($localThemes[$dir])) {
                    continue;
                }
                $iniFile = $themesDir . '/' . $dir
                    . '/config/theme.ini';
                if (file_exists($iniFile)) {
                    $ini = parse_ini_file($iniFile);
                    $localThemes[$dir] = [
                        'dir' => $dir,
                        'path' => $themesDir . '/' . $dir,
                        'name' => $ini['name'] ?? $dir,
                        'version' => $ini['version'] ?? '',
                        'description' => $ini['description'] ?? '',
                        'author' => $ini['author'] ?? '',
                        'theme_link' => $ini['theme_link'] ?? '',
                        'omeka_version_constraint' => $ini['omeka_version_constraint'] ?? '',
                    ];
                }
            }
        }
        uasort($localThemes, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $state = $this->params()->fromQuery('state');

        $request = $this->getRequest();
        if ($request->isPost()) {
            return $this->handlePost($addons);
        }

        $manageForm = $this->getForm(
            \EasyAdmin\Form\AddonManageForm::class
        );

        $stateForm = function (string $action, string $themeId) {
            $form = $this->getForm(ModuleStateForm::class);
            $form->setAttribute('action', $this->url()->fromRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme', 'action' => $action],
                ['query' => ['id' => $themeId]]
            ));
            $form->get('id')->setValue($themeId);
            return $form;
        };

        // Build the install form for the sidebar.
        $installCatalogueForm = $this->getForm(
            ModuleStateForm::class
        );
        $installCatalogueForm->setAttribute(
            'action',
            $this->url()->fromRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme', 'action' => 'install']
            )
        );
        $installCatalogueForm->setAttribute('method', 'post');

        // Get sites grouped by theme for usage indicator.
        $connection = $this->getEvent()->getApplication()
            ->getServiceManager()->get('Omeka\Connection');
        $sitesByTheme = [];
        $rows = $connection->executeQuery(
            'SELECT `theme`, `slug`, `title` FROM `site` ORDER BY `title`'
        )->fetchAllAssociative();
        foreach ($rows as $row) {
            $sitesByTheme[$row['theme']][] = $row;
        }

        $view = new ViewModel([
            'omekaThemes' => $omekaThemes,
            'webThemes' => $webThemes,
            'localThemes' => $localThemes,
            'sitesByTheme' => $sitesByTheme,
            'filterState' => $state,
            'manageForm' => $manageForm,
            'stateForm' => $stateForm,
            'installCatalogueForm' => $installCatalogueForm,
        ]);
        $view->setTemplate('easy-admin/admin/theme/browse');
        return $view;
    }

    public function installAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();

        $urls = $this->params()->fromPost('theme_urls', []);
        if (!is_array($urls) || !$urls) {
            $this->messenger()->addError(
                'No theme selected.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $toInstall = [];
        foreach ($urls as $url) {
            $addon = $addons->dataFromUrl($url, 'theme')
                ?: $addons->dataFromUrl($url, 'omekatheme');
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
                'No new theme to install.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        if (count($toInstall) > 3) {
            $job = $this->jobDispatcher()->dispatch(
                \EasyAdmin\Job\ManageAddons::class,
                [
                    'operation' => 'install',
                    'addons' => $toInstall,
                ]
            );
            $urlPlugin = $this->url();
            $message = new PsrMessage(
                'Installing {count} themes in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
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
            ['controller' => 'theme']
        );
    }

    public function updateConfirmAction()
    {
        $id = $this->params()->fromQuery('id');
        if (!$id) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'Unknown theme "{name}".', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $addon['installed_version'] = $addons
            ->getInstalledVersion($addon);

        $integrity = $addons->checkIntegrity($addon);

        $form = $this->getForm(ModuleStateForm::class);
        $form->setAttribute('method', 'post');
        $form->setAttribute('action', $this->url()->fromRoute(
            'admin/easy-admin/default',
            ['controller' => 'theme', 'action' => 'update']
        ));
        $form->get('id')->setValue($addon['dir'] ?? '');

        $view = new ViewModel([
            'addon' => $addon,
            'integrity' => $integrity,
            'form' => $form,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/theme/update-confirm'
        );
        return $view;
    }

    public function updateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        if (!$id) {
            $this->messenger()->addError(
                'No theme specified.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'Unknown theme "{name}".', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $addons->updateAddon($addon);

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'theme']
        );
    }

    public function showDetailsAction()
    {
        $id = $this->params()->fromQuery('id');

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);

        $themePath = $this->resolveThemePath($id);
        $iniFile = $themePath
            ? $themePath . '/config/theme.ini'
            : null;
        $ini = $iniFile && file_exists($iniFile)
            ? (parse_ini_file($iniFile) ?: [])
            : [];

        $integrity = $addon
            ? $addons->checkIntegrity($addon)
            : null;

        // Sites using this theme.
        $connection = $this->getEvent()->getApplication()
            ->getServiceManager()->get('Omeka\Connection');
        $themeSites = $connection->executeQuery(
            'SELECT `slug`, `title` FROM `site`'
            . ' WHERE `theme` = ? ORDER BY `title`',
            [$id]
        )->fetchAllAssociative();

        $view = new ViewModel([
            'themeId' => $id,
            'ini' => $ini,
            'addon' => $addon,
            'integrity' => $integrity,
            'themeSites' => $themeSites,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/theme/show-details'
        );
        return $view;
    }

    public function removeConfirmAction()
    {
        $id = $this->params()->fromQuery('id');

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);

        $integrity = $addon
            ? $addons->checkIntegrity($addon)
            : ['status' => 'unknown'];

        $form = $this->getForm(ModuleStateForm::class);
        $form->setAttribute('action', $this->url()->fromRoute(
            'admin/easy-admin/default',
            ['controller' => 'theme', 'action' => 'remove']
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
            'easy-admin/admin/theme/remove-confirm'
        );
        return $view;
    }

    public function removeAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        if (!$id) {
            $this->messenger()->addError(
                'No theme specified.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $addon = [
                'type' => 'theme',
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
            ['controller' => 'theme']
        );
    }

    public function integrityAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
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
            $addon = $addons->dataFromNamespace($id, 'theme')
                ?: $addons->dataFromNamespace($id);
            if (!$addon) {
                $addon = [
                    'type' => 'theme',
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
            $report = [];
            $dirs = [];
            foreach ($this->getThemesDirs() as $themesDir) {
                foreach (array_diff(scandir($themesDir) ?: [], ['.', '..']) as $dir) {
                    if (!isset($dirs[$dir]) && is_dir($themesDir . '/' . $dir)) {
                        $dirs[$dir] = true;
                    }
                }
            }
            foreach (array_keys($dirs) as $dir) {
                $addon = $addons->dataFromNamespace($dir, 'theme')
                    ?: $addons->dataFromNamespace($dir);
                if (!$addon) {
                    $addon = [
                        'type' => 'theme',
                        'name' => $dir,
                        'dir' => $dir,
                        'basename' => $dir,
                        'url' => '',
                        'zip' => '',
                        'version' => '',
                        'dependencies' => [],
                    ];
                }
                $report[$dir] = $addons->checkIntegrity(
                    $addon,
                    $fromSource
                );
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'theme', 'action' => 'integrity-report'],
            ['query' => ['report' => json_encode($report)]]
        );
    }

    public function integrityReportAction()
    {
        $reportJson = $this->params()->fromQuery('report', '{}');
        $report = json_decode($reportJson, true) ?: [];

        $view = new ViewModel([
            'report' => $report,
        ]);
        $view->setTemplate(
            'easy-admin/admin/theme/integrity-report'
        );
        return $view;
    }

    /**
     * Get all theme directories (local takes precedence).
     *
     * @return string[]
     */
    protected function getThemesDirs(): array
    {
        return array_filter([
            OMEKA_PATH . '/themes',
            OMEKA_PATH . '/composer-addons/themes',
        ], 'is_dir');
    }

    /**
     * Resolve a theme id to its filesystem path.
     */
    protected function resolveThemePath(string $themeId): ?string
    {
        foreach ($this->getThemesDirs() as $themesDir) {
            $path = $themesDir . '/' . $themeId;
            if (is_dir($path)) {
                return $path;
            }
        }
        return null;
    }

    protected function handlePost($addons): \Laminas\Http\Response
    {
        $post = $this->params()->fromPost();
        $action = $post['bulk_action'] ?? '';
        $selected = $post['themes'] ?? [];

        if ($action && $selected) {
            foreach ($selected as $themeId) {
                switch ($action) {
                    case 'update':
                        $addon = $addons->dataFromNamespace(
                            $themeId,
                            'theme'
                        ) ?: $addons->dataFromNamespace($themeId);
                        if ($addon) {
                            $addons->updateAddon($addon);
                        }
                        break;

                    case 'remove':
                        $addon = $addons->dataFromNamespace(
                            $themeId,
                            'theme'
                        ) ?: $addons->dataFromNamespace($themeId);
                        if (!$addon) {
                            $addon = [
                                'type' => 'theme',
                                'name' => $themeId,
                                'dir' => $themeId,
                                'basename' => $themeId,
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
            ['controller' => 'theme']
        );
    }
}

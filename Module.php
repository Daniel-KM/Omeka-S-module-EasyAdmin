<?php declare(strict_types=1);

/*
 * Copyright 2017-2026 Daniel Berthereau
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

// Load the module dependencies when installed as a zip.
// With composer, libraries are stored in omeka vendor/ and the module has none.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Common may be installed but not registered in autoloader, in particular
// during upgrade. So dynamically register all classes of the module.
if (!defined('COMMON_PSR4_FALLBACK')) {
    foreach ([
        OMEKA_PATH . '/modules/Common/src',
        OMEKA_PATH . '/composer-addons/modules/Common/src',
        dirname(__DIR__) . '/Common/src',
    ] as $commonSrc) {
        if (file_exists($commonSrc . '/TraitModule.php')) {
            define('COMMON_PSR4_FALLBACK', $commonSrc);
            spl_autoload_register(static function ($class): void {
                if (str_starts_with($class, 'Common\\')) {
                    $file = COMMON_PSR4_FALLBACK . '/' . strtr(substr($class, 7), '\\', '/') . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
            break;
        }
    }
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleEvent;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Laminas\Session\Container;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Module\AbstractModule;

/**
 * Easy Admin.
 *
 * @copyright Daniel Berthereau, 2017-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    public function init(ModuleManager $moduleManager): void
    {

        // Run last so the thumbnailer alias set by the core, local config or
        // another module is captured as default if not overridden by EasyAdmin.
        $moduleManager->getEventManager()->attach(
            ModuleEvent::EVENT_MERGE_CONFIG,
            [$this, 'onEventMergeConfig'],
            -100
        );
    }

    /**
     * Take over the "Omeka\File\Thumbnailer" alias so it can be overridden.
     */
    public function onEventMergeConfig(ModuleEvent $event): void
    {
        /** @var \Laminas\ModuleManager\Listener\ConfigListener $configListener */
        $configListener = $event->getParam('configListener');
        $config = $configListener->getMergedConfig(false);

        $current = $config['service_manager']['aliases']['Omeka\File\Thumbnailer']
            ?? \Omeka\File\Thumbnailer\ImageMagick::class;
        if ($current === 'EasyAdmin\File\Thumbnailer\Configured') {
            return;
        }

        $config['easyadmin']['thumbnailer_default'] = $current;
        $config['service_manager']['aliases']['Omeka\File\Thumbnailer'] = 'EasyAdmin\File\Thumbnailer\Configured';

        $configListener->setMergedConfig($config);
    }

    public function getConfig()
    {
        $config = include __DIR__ . '/config/module.config.php';
        // Fix #2236 is integrated in 4.2, so remove it for newer versions.
        if (version_compare(\Omeka\Module::VERSION, '4.2', '>=')) {
            unset($config['form_elements']['factories']['Omeka\Form\AssetEditForm']);
        }
        // HTTP/2 support (curl adapter autodetection) is integrated in the core
        // http client since 4.3, so let the core factory take over for newer
        // versions and keep this override only for older ones.
        if (version_compare(\Omeka\Module::VERSION, '4.3', '>=')) {
            unset($config['service_manager']['factories']['Omeka\HttpClient']);
        }
        return $config;
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var \Omeka\Settings\Settings $settings */
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $displayException = $settings->get('easyadmin_display_exception');
        if ($displayException) {
            /** @var \Omeka\Mvc\Status $status */
            $status = $this->getServiceLocator()->get('Omeka\Status');
            if ($displayException === 'all' || $status->isAdminRequest()) {
                ini_set('display_errors', '1');
                ini_set('log_errors', '1');
                // Log uncaught fatal php errors through the standard logger.
                // Unlike mvc exceptions handled by Omeka\Mvc\ExceptionListener, fatal
                // errors don't go through mvc layer, so they are only displayed and
                // never recorded.
                // See the job fatal handling in Job\DispatchStrategy\Synchronous.
                if (version_compare(\Omeka\Module::VERSION, '4.3', '<')) {
                    $services = $this->getServiceLocator();
                    register_shutdown_function(function () use ($services): void {
                        $error = error_get_last();
                        $fatals = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
                        if ($error && ($error['type'] & $fatals)) {
                            try {
                                $services->get('Omeka\Logger')->err(
                                    'Fatal error: {message} in {file}:{line}', // @translate
                                    ['message' => $error['message'], 'file' => $error['file'], 'line' => $error['line']]
                                );
                            } catch (\Throwable $e) {
                                // Logger may be unavailable during shutdown.
                            }
                        }
                    });
                }
            }
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
                    'download',
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
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.91')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.91'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $errors = [];

        $js = __DIR__ . '/asset/vendor/flow.js/flow.min.js';
        if (!file_exists($js)) {
            $message = new PsrMessage(
                'The libraries should be installed. See module’s installation documentation.' // @translate
            );
            $errors[] = (string) $message->setTranslator($translator);
        }

        if ($errors) {
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(implode("\n", $errors));
        }

        $this->installDirs();

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $settings = $services->get('Omeka\Settings');
        $settings->set('easyadmin_local_path', $settings->get('bulkimport_local_path') ?: $basePath . '/import');
        $directories = array_filter(array_unique([
            $basePath . '/backup',
            $basePath . '/check',
            $basePath . '/import',
            $settings->get('easyadmin_local_path') ?: $basePath . '/import',
        ]));
        sort($directories);
        $settings->set('easyadmin_local_paths', $directories);
        $settings->set('easyadmin_allow_empty_files', (bool) $settings->get('bulkimport_allow_empty_files'));
    }

    protected function postInstall(): void
    {
        /**
         * @var \Omeka\Settings\Settings  $settings
         * @var \Common\Stdlib\EasyMeta $easyMeta
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $settings->set('easyadmin_cron_tasks', ['session_8']);

        $this->postInstallAuto();

        // Install default templates.
        $easyMeta = $services->get('Common\EasyMeta');
        $val = array_values($easyMeta->resourceTemplateIds());
        $settings->set('easyadmin_quick_template', $val);
    }

    /**
     * Move the content of a directory into another one, without overwriting.
     *
     * The source directory is removed when all its content is moved.
     *
     * @return int The number of entries that remain in the source directory.
     */
    protected function mergeDirectoryInto(string $source, string $destination): int
    {
        $remaining = 0;
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $name) {
            $from = $source . '/' . $name;
            $to = $destination . '/' . $name;
            if (!file_exists($to)) {
                if (!@rename($from, $to)) {
                    ++$remaining;
                }
            } elseif (is_dir($from) && is_dir($to) && !is_link($from)) {
                $remaining += $this->mergeDirectoryInto($from, $to);
            } else {
                ++$remaining;
            }
        }
        if (!$remaining) {
            @rmdir($source);
        }
        return $remaining;
    }

    protected function installDirs(): void
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        // Automatic upgrade from module Bulk Check.
        $result = null;
        $bulkCheckPath = $basePath . '/bulk_check';
        $checkPath = $basePath . '/check';
        if (is_dir($bulkCheckPath)) {
            if (!file_exists($checkPath)) {
                $result = @rename($bulkCheckPath, $checkPath);
                if (!$result) {
                    $messenger->addWarning(new PsrMessage(
                        'Upgrading module BulkCheck: Unable to rename directory "files/bulk_check" into "files/check". Trying to create it.' // @translate
                    ));
                }
            } else {
                // The directory "check" exists already, so the content of the
                // old one is moved into it, without overwriting any file, and
                // the old one is removed when it is empty.
                $remaining = $this->mergeDirectoryInto($bulkCheckPath, $checkPath);
                if ($remaining) {
                    $messenger->addWarning(new PsrMessage(
                        'Upgrading module BulkCheck: {count} files of directory "files/bulk_check" were not moved into "files/check", because they exist there already or cannot be moved. Check them, then remove the directory.', // @translate
                        ['count' => $remaining]
                    ));
                }
            }
        }

        $disabled = [];
        if (!$result && !$this->checkDestinationDir($basePath . '/check')) {
            $disabled[] = $basePath . '/check';
        }
        if (!$this->checkDestinationDir($basePath . '/backup', true)) {
            $disabled[] = $basePath . '/backup';
        }
        if (!$this->checkDestinationDir($basePath . '/import', true)) {
            $disabled[] = $basePath . '/import';
        }
        if ($disabled) {
            $messenger->addWarning(new PsrMessage(
                'These directories are not writeable: {dirs}. Related features (check, backup, bulk import) are disabled until permissions are fixed.', // @translate
                ['dirs' => implode(', ', $disabled)]
            ));
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
            $connection->executeStatement('DELETE FROM `module` WHERE `id` = ?', [$moduleName]);
            $connection->executeStatement('DELETE FROM `setting` WHERE `id` LIKE ?', [strtolower($moduleName) . '\_%']);
            $connection->executeStatement('DELETE FROM `site_setting` WHERE `id` LIKE ?', [strtolower($moduleName) . '\_%']);
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
        // Handle cron tasks: use Cron module if available, otherwise run
        // independently via view.layout trigger.
        if (class_exists('Cron\Module', false)) {
            // Handle session cleanup when triggered by Cron module.
            $sharedEventManager->attach(
                \Cron\Job\CronTasks::class,
                'cron.execute',
                [$this, 'handleCronExecute']
            );
        } else {
            // Handle cron tasks independently when Cron module is not installed.
            // Triggered on any admin page load.
            $sharedEventManager->attach(
                '*',
                'view.layout',
                [$this, 'handleCron']
            );
        }

        // Set the color of the admin left sidebar.
        $sharedEventManager->attach(
            '*',
            'view.layout',
            [$this, 'handleAdminBoardColor']
        );

        // Enrich the system information page with the diagnostics of the
        // PHP-CLI used by background jobs. The core triggers the "system_info"
        // event since Omeka S 4.2 (before, the listener is simply never
        // called).
        $sharedEventManager->attach(
            \Omeka\Controller\Admin\SystemInfoController::class,
            'system_info',
            [$this, 'handleSystemInfo']
        );

        // Add the "Apply" button (save and stay on the form) on every module
        // config page. The button is added on render (configure GET) and the
        // core redirect to the module list is rewritten to the config form on
        // an apply submit (dispatch runs after the controller action).
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.layout',
            [$this, 'handleConfigApplyButton']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'dispatch',
            [$this, 'handleConfigApplyRedirect'],
            -100
        );

        // Manage buttons in admin resources.
        // Uses view.layout instead of Omeka 4.1 view.show.page_actions because
        // page_actions does not exist for browse pages.
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
        // Item sets and media browse are not handled (less common use case).
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

        // Add search by name for assets (issue Common #3).
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\AssetAdapter::class,
            'api.search.query',
            [$this, 'handleAssetSearchQuery']
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

        // Load sortable js on the settings page.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Setting',
            'view.browse.before',
            [$this, 'addHeadersSettings']
        );

        // Same filter and group navigation on the site settings section of the
        // site edit page.
        $sharedEventManager->attach(
            'Omeka\Controller\SiteAdmin\Index',
            'view.edit.before',
            [$this, 'addHeadersSiteSettings']
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

    /**
     * Handle task execution from Cron module.
     */
    public function handleCronExecute(Event $event): void
    {
        $taskId = $event->getParam('task_id');
        $taskSettings = $event->getParam('task_settings') ?: [];

        // New contract: real task id ("session", "backup_database",
        // "backup_files") + a "params" map of clean values. Legacy contract:
        // the flattened id as the task id, whose suffix is the clean value
        // (e.g. "session_8d" -> "8d"), kept until settings are migrated.
        $params = $taskSettings['params'] ?? [];

        // Handle session cleanup tasks.
        if ($taskId === 'session' || strncmp($taskId, 'session_', 8) === 0) {
            $age = $params['age'] ?? (strncmp($taskId, 'session_', 8) === 0 ? substr($taskId, 8) : '');
            $this->executeSessionCleanup((string) $age);
            $event->setParam('handled', true);
            return;
        }

        // Handle database backup tasks.
        if ($taskId === 'backup_database' || strncmp($taskId, 'backup_db_', 10) === 0) {
            $format = $params['format'] ?? (strncmp($taskId, 'backup_db_', 10) === 0 ? substr($taskId, 10) : '');
            $this->executeBackupDatabase((string) $format);
            $event->setParam('handled', true);
            return;
        }

        // Handle files backup tasks.
        if ($taskId === 'backup_files' || strncmp($taskId, 'backup_files_', 13) === 0) {
            $scope = $params['scope'] ?? (strncmp($taskId, 'backup_files_', 13) === 0 ? substr($taskId, 13) : '');
            $this->executeBackupFiles((string) $scope);
            $event->setParam('handled', true);
            return;
        }
    }

    /**
     * Execute session cleanup.
     */
    protected function executeSessionCleanup(string $age): void
    {
        $seconds = \EasyAdmin\Job\DbSession::secondsForAge($age);
        if ($seconds === null) {
            return;
        }

        $services = $this->getServiceLocator();
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $time = time();

        // Check if index exists for performance.
        $result = $connection->executeQuery(
            'SHOW INDEX FROM `session` WHERE `column_name` = "modified";'
        );

        if ($result->fetchOne()) {
            // Direct delete with index.
            $sql = 'DELETE FROM `session` WHERE `modified` < :time;';
            $connection->executeStatement(
                $sql,
                ['time' => $time - $seconds],
                ['time' => \Doctrine\DBAL\ParameterType::INTEGER]
            );
        } else {
            // Dispatch as background job for tables without index.
            $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
            $dispatcher->dispatch(\EasyAdmin\Job\DbSession::class, [
                'seconds' => $seconds,
                'quick' => true,
            ]);
        }
    }

    /**
     * Execute database backup via Cron.
     */
    protected function executeBackupDatabase(string $format): void
    {
        $services = $this->getServiceLocator();
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);

        $compress = ($format !== 'plain');

        $dispatcher->dispatch(\EasyAdmin\Job\DatabaseBackup::class, [
            'compress' => $compress,
        ]);
    }

    /**
     * Execute files backup via Cron.
     */
    protected function executeBackupFiles(string $scope): void
    {
        $services = $this->getServiceLocator();
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);

        if ($scope === 'full') {
            $include = ['core', 'modules', 'themes', 'local_config', 'database_ini', 'htaccess'];
        } else {
            // Config only.
            $include = ['local_config', 'database_ini', 'htaccess'];
        }

        $dispatcher->dispatch(\EasyAdmin\Job\Backup::class, [
            'process' => 'backup_install',
            'include' => $include,
            'compression' => 6,
        ]);
    }

    /**
     * Handle cron tasks independently when Cron module is not installed.
     *
     * This method is triggered on view.layout to execute scheduled tasks
     * without requiring the Cron module.
     */
    public function handleAdminBoardColor(Event $event): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Status')->isAdminRequest()) {
            return;
        }

        $color = (string) $services->get('Omeka\Settings')
            ->get('easyadmin_admin_board_color', '');
        if ($color === '') {
            return;
        }

        // The class "admin-board" is not set in all versions of the layout.
        $event->getTarget()->headStyle()->appendStyle(
            'body > .flex > header { background-color: ' . $color . '; }'
        );
    }

    public function handleCron(Event $event): void
    {
        // Quick static check to avoid reading settings on every page load.
        // After the first check within the hour, all subsequent requests
        // skip the settings read entirely.
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $services = $this->getServiceLocator();

        // Independent mode: execute cron tasks without the Cron module.
        $settings = $services->get('Omeka\Settings');

        // Check frequency first (cheapest check) before loading tasks.
        $lastCron = (int) $settings->get('easyadmin_cron_last');
        $time = time();
        if ($lastCron + 3600 > $time) {
            return;
        }

        $enabledTasks = $this->getEnabledCronTasks($settings);
        if (!count($enabledTasks)) {
            return;
        }

        $settings->set('easyadmin_cron_last', $time);

        // Dispatch the CronTasks job.
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $dispatcher->dispatch(\EasyAdmin\Job\CronTasks::class, [
            'tasks' => $enabledTasks,
            'manual' => false,
        ]);
    }

    /**
     * Get enabled cron tasks from settings.
     *
     * Supports both new structure (easyadmin_cron with nested tasks) and
     * legacy structure (easyadmin_cron_tasks as flat array).
     */
    protected function getEnabledCronTasks(\Omeka\Settings\Settings $settings): array
    {
        // New structure.
        $cronSettings = $settings->get('easyadmin_cron', []);
        if (!empty($cronSettings['tasks'])) {
            $enabledTasks = [];
            foreach ($cronSettings['tasks'] as $taskId => $taskSettings) {
                if (!empty($taskSettings['enabled'])) {
                    $enabledTasks[$taskId] = $taskSettings;
                }
            }
            return $enabledTasks;
        }

        // Legacy structure for backward compatibility.
        $oldTasks = $settings->get('easyadmin_cron_tasks', []);
        if (!count($oldTasks)) {
            return [];
        }
        $enabledTasks = [];
        foreach ($oldTasks as $taskId) {
            $enabledTasks[$taskId] = ['enabled' => true, 'frequency' => 'hourly'];
        }
        return $enabledTasks;
    }

    /**
     * Add the "Apply" button asset on the module config form (configure page).
     */
    /**
     * Add the PHP-CLI diagnostics of the background jobs to the system
     * information page (without a light candidate scan, to keep it fast).
     */
    public function handleSystemInfo(Event $event): void
    {
        $services = $this->getServiceLocator();
        $strategy = get_class($services->get('Omeka\Job\Dispatcher')->getDispatchStrategy());
        $checker = new \EasyAdmin\Stdlib\JobCliChecker(
            $services->get('Omeka\Cli'),
            $services->get('Config'),
            $strategy
        );
        $r = $checker->check(false);

        $path = is_string($r['effective']) && $r['effective'] !== '' ? $r['effective'] : '[none]';
        if ($r['configured'] && $r['effective'] === false) {
            $path = $r['configured'] . ' [invalid]';
        }
        $version = '[not run]';
        if ($r['effectiveInfo']) {
            $version = sprintf(
                '%s (%s)%s',
                $r['effectiveInfo']['version'],
                $r['effectiveInfo']['sapi'] !== '' ? $r['effectiveInfo']['sapi'] : 'unknown',
                $r['versionMatch'] ? ' [matches web]' : ' [differs from web ' . $r['web']['version'] . ']'
            );
        }

        $block = [
            'Dispatch strategy' => $r['dispatchStrategy'] ?? '[default]',
            'Web can spawn process' => $r['canSpawn']['ok'] ? 'yes' : 'no (proc_open and exec disabled)',
            'PHP-CLI path' => $path,
            'PHP-CLI version' => $version,
            'pdo_mysql' => $r['hasPdoMysql'] === null ? '[unknown]' : ($r['hasPdoMysql'] ? 'yes' : 'no'),
            'open_basedir' => $r['openBasedir'] ? implode(', ', $r['openBasedir']) : '[none]',
        ];
        if ($r['missingExtensions']) {
            $block['PHP-CLI missing extensions'] = implode(', ', $r['missingExtensions']);
        }

        $info = $event->getParam('info');
        $info['Background jobs'] = $block;
        $event->setParam('info', $info);
    }

    public function handleConfigApplyButton(Event $event): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Settings')->get('easyadmin_config_apply_button')) {
            return;
        }

        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();
        if (($view->params()->fromRoute('action') ?? '') !== 'configure') {
            return;
        }

        $view->headScript()
            ->appendScript(sprintf('var CommonConfigApply = %s;', json_encode(['label' => $view->translate('Apply')])))
            ->appendFile($view->assetUrl('js/common-config-apply.js', 'Common'));
    }

    /**
     * Stay on the module config form on an "apply" submit: rewrite the core
     * redirect (to the module list) to the configure action. Runs after the
     * controller action (negative priority), so the 302 response is available.
     */
    public function handleConfigApplyRedirect(MvcEvent $event): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Settings')->get('easyadmin_config_apply_button')) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isPost() || !$request->getPost('apply')) {
            return;
        }

        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch || $routeMatch->getParam('action') !== 'configure') {
            return;
        }

        /** @var \Laminas\Http\Response $response */
        $response = $event->getResponse();
        if (!$response instanceof \Laminas\Http\Response || !$response->isRedirect()) {
            return;
        }

        $id = (string) $request->getQuery('id');
        if ($id === '') {
            return;
        }

        $options = [
            'name' => 'admin/default',
            'query' => ['id' => $id],
        ];
        $fragment = (string) $request->getPost('apply_fragment');
        if ($fragment !== '') {
            $options['fragment'] = $fragment;
        }
        $url = $event->getRouter()->assemble(
            ['controller' => 'module', 'action' => 'configure'],
            $options
        );

        $headers = $response->getHeaders();
        if ($headers->has('Location')) {
            $headers->removeHeader($headers->get('Location'));
        }
        $headers->addHeaderLine('Location', $url);
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
            // Modules.
            'annotation' => 'annotations',
            'Annotate\Controller\Admin\Annotation' => 'annotations',
            'Annotate\Controller\Admin\AnnotationController' => 'annotations',
            'digital-object' => 'digital_objects',
            'DigitalObject\Controller\Admin\DigitalObject' => 'digital_objects',
            'DigitalObject\Controller\Admin\DigitalObjectController' => 'digital_objects',
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
        $aclResource = $adapter instanceof \Laminas\Permissions\Acl\Resource\ResourceInterface
            ? $adapter->getResourceId()
            : get_class($adapter);
        if (!$user || !$acl->userIsAllowed($aclResource, 'create')) {
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
        $classIds = $classTerms ? $easyMeta->resourceClassIds($classTerms) : [];
        $templateClassLabels = [];
        if ($classIds) {
            // Omeka doesn't support searching resource templates by class,
            // so sql is used.
            // TODO Use api search when Omeka allows searching resource templates by class.
            // The module AdvancedResourceTemplate allows to suggest multiple
            // classes by template.
            if ($this->isModuleActive('AdvancedResourceTemplate')) {
                // Use DBAL to avoid loading all templates with full hydration.
                $connection = $services->get('Omeka\Connection');
                $sql = <<<'SQL'
                    SELECT rt.id, rt.label, rtd.data
                    FROM resource_template AS rt
                    INNER JOIN resource_template_data AS rtd ON rtd.resource_template_id = rt.id
                    ORDER BY rt.label ASC
                    SQL;
                $rows = $connection->executeQuery($sql)->fetchAllAssociative();
                foreach ($rows as $row) {
                    $data = json_decode($row['data'], true);
                    if (!$data) {
                        continue;
                    }
                    $useForResources = $data['use_for_resources'] ?? null;
                    if ($useForResources && !in_array($resourceType, $useForResources)) {
                        continue;
                    }
                    $suggestedClasses = $data['suggested_resource_class_ids'] ?? [];
                    if ($ids = array_intersect($suggestedClasses, $classIds)) {
                        $templateClassLabels[(int) $row['id']] = ['label' => $row['label'], 'resource_class_id' => reset($ids)];
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
                    ->addOrderBy('`resource_template`.`label`', 'asc')
                ;
                $templateClassLabels = $qb->execute()->fetchAllAssociative();
            }
        }

        if (!$templateLabels && !$templateClassLabels) {
            return;
        }

        // Templates are already filtered by AdvancedResourceTemplate above
        // (use_for_resources).

        $plugins = $view->getHelperPluginManager();
        $url = $plugins->get('url');
        $escape = $plugins->get('escapeHtmlAttr');
        $translate = $plugins->get('translate');
        $hyperlink = $plugins->get('hyperlink');

        $vars = $view->vars();
        $html = $vars->offsetGet('content');

        $mainLabels = [
            'items' => 'Add new item',
            'item_sets' => 'Add new item set',
            'media' => 'Add new media',
            'annotations' => 'Add new annotation',
            'digital_objects' => 'Add new digital object',
        ];

        $buttons = [];
        foreach ($templateLabels as $id => $label) {
            $buttons[$id] = $hyperlink($translate($label), $url(null, ['action' => 'add'], ['query' => ['resource_template_id' => $id]], true), ['class' => 'link']);
        }

        foreach ($templateClassLabels as $id => $data) {
            $buttons[$id] = $hyperlink($translate($data['label']), $url(null, ['action' => 'add'], ['query' => ['resource_template_id' => $id, 'resource_class_id' => $data['resource_class_id']]], true), ['class' => 'link']);
        }

        if (count($buttons) > 1) {
            // Uses anchor links (styled as buttons) for simplicity for now.
            // TODO Use a real button instead of an anchor for actions for accessibility.
            $stringButtons = '<li>' . implode("</li>\n<li>", $buttons) . "</li>\n";
            $stringExpand = $escape($translate('Expand'));
            $urlAdd = $url(null, ['action' => 'add'], [], true);
            $stringAdd = $escape($translate($mainLabels[$resourceType]));
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
                            <a class="button" href="$urlAdd">$stringAdd</a>
                            <a href="#" class="expand button expand-more" aria-label="$stringExpand>"></a>
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
                $resource = $services->get('Omeka\ApiManager')->read($resourceType, ['id' => $id], [], ['initialize' => false])->getContent();
            } catch (\Throwable $e) {
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
                    $needle = '<h4>' . $translated . '</h4>';
                    $hPos = strpos($html, $needle);
                    if ($hPos !== false) {
                        $start = strrpos(substr($html, 0, $hPos), '<div class="meta-group');
                        $next = strpos($html, '<div class="meta-group', $hPos);
                        if ($start !== false && $next !== false) {
                            $html = substr($html, 0, $start) . $htmlSites . substr($html, $next);
                        }
                    }
                } else {
                    $translated = $resourceType === 'item_sets' ? $translate('Items') : $translate('Created');
                    $needle = '<h4>' . $translated . '</h4>';
                    $hPos = strpos($html, $needle);
                    if ($hPos !== false) {
                        $start = strrpos(substr($html, 0, $hPos), '<div class="meta-group');
                        if ($start !== false) {
                            $html = substr($html, 0, $start) . $htmlSites . substr($html, $start);
                        }
                    }
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
            // Omeka shows public view links natively for resources with sites:
            // - Items: since omeka s v4.1
            // - Item sets: since omeka s v4.2
            $resourceName = $resource->resourceName();
            $skip = false;
            if ($resourceName === 'items' && count($resource->sites())) {
                $skip = version_compare(\Omeka\Module::VERSION, '4.1', '>=');
            } elseif ($resourceName === 'item_sets' && count($resource->sites())) {
                $skip = version_compare(\Omeka\Module::VERSION, '4.2', '>=');
            }
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
            $htmlSites .= strtr($htmlSite, $replace);
        }

        // The class item-sites is kept for css.
        $translatedSites = $translate('Sites'); // @translate
        $html = <<<HTML
            <div class="meta-group $controller-sites item-sites">
                <h4>$translatedSites</h4>
                $htmlSites
            </div>
            <style>.sidebar .meta-group.item-sites .o-icon-external{float:right}</style>
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

    public function addHeadersSettings(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        if ($this->settingsEnhancementsEnabled()) {
            $this->appendSettingsFilterAssets($view, \Omeka\Form\SettingForm::class);
        } else {
            $this->appendSettingsEnhancementsButton($view);
        }
        $view->headScript()
            ->appendFile($assetUrl('vendor/sortablejs/Sortable.min.js', 'Omeka'))
            ->appendFile($assetUrl('js/chosen-sortable.js', 'EasyAdmin'), 'text/javascript', ['defer' => 'defer']);
    }

    /**
     * The site edit page holds the site settings in its own section; the same
     * filter and group navigation as the global settings page apply to it.
     */
    public function addHeadersSiteSettings(Event $event): void
    {
        $view = $event->getTarget();
        if ($this->settingsEnhancementsEnabled()) {
            $this->appendSettingsFilterAssets($view, \Omeka\Form\SiteSettingsForm::class);
        } else {
            $this->appendSettingsEnhancementsButton($view);
        }
    }

    protected function settingsEnhancementsEnabled(): bool
    {
        return (bool) $this->getServiceLocator()->get('Omeka\Settings')
            ->get('easyadmin_settings_enhancements', false);
    }

    /**
     * Add a button on the settings pages to enable the settings enhancements,
     * only when they are disabled.
     */
    protected function appendSettingsEnhancementsButton(PhpRenderer $view): void
    {
        $translate = $view->plugin('translate');
        $url = $view->plugin('url')('admin/easy-admin/default', [
            'controller' => 'check-and-fix',
            'action' => 'enable-settings-enhancements',
        ]);
        $validator = new \Laminas\Validator\Csrf(['name' => 'easyadmin_enable_enhancements']);
        $token = $validator->getHash();
        $label = $translate('Enable filters'); // @translate
        $title = $translate('Add a live filter and a section navigation to this page.'); // @translate
        $data = json_encode([
            'url' => $url,
            'csrf' => $token,
            'label' => $label,
            'title' => $title,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $view->headScript()
            ->appendScript(sprintf('window.EasyAdmin=window.EasyAdmin||{};window.EasyAdmin.enableEnhancements=%s;', $data))
            ->appendFile($view->plugin('assetUrl')('js/settings-enhancements-enable.js', 'EasyAdmin'), 'text/javascript', ['defer' => 'defer']);
    }

    protected function appendSettingsFilterAssets(PhpRenderer $view, string $formClass): void
    {
        $services = $this->getServiceLocator();
        $assetUrl = $view->plugin('assetUrl');
        $translate = $view->plugin('translate');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/easy-admin.css', 'EasyAdmin'));
        $form = $services->get('FormElementManager')->get($formClass);
        $kinds = (new \EasyAdmin\Stdlib\SettingKindClassifier())->classify($form);
        $status = $this->settingFieldStatus(array_keys($kinds), $formClass, $view);
        $strings = json_encode([
            'placeholder' => $translate('Filter settings…'), // @translate
            'count' => $translate('%s settings'), // @translate
            'nav' => $translate('Sections'), // @translate
            'textFields' => $translate('Text fields'), // @translate
            'nonTextFields' => $translate('Non-text fields'), // @translate
            'includeValues' => $translate('Include values'), // @translate
            'modified' => $translate('Modified'), // @translate
            'default' => $translate('Default'), // @translate
            'unknown' => $translate('Unknown'), // @translate
            'groupValue' => $translate('Value'), // @translate
            'groupType' => $translate('Type'), // @translate
            'kinds' => $kinds,
            'status' => $status,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $view->headScript()
            ->appendScript(sprintf('window.EasyAdmin=window.EasyAdmin||{};window.EasyAdmin.settingsFilter=%s;', $strings))
            ->appendFile($assetUrl('js/settings-filter.js', 'EasyAdmin'), 'text/javascript', ['defer' => 'defer'])
            ->appendFile($assetUrl('js/settings-nav.js', 'EasyAdmin'), 'text/javascript', ['defer' => 'defer']);
    }

    /**
     * Status of each setting: default, modified or unknown.
     *
     * Modules based on the Common module declare their default settings in
     * module.config.php under the module namespace ("settings" or
     * "site_settings"), so the current value can be compared to the default.
     * Core settings and settings of other modules have no known default and are
     * reported as unknown.
     */
    protected function settingFieldStatus(array $names, string $formClass, PhpRenderer $view): array
    {
        $services = $this->getServiceLocator();
        $isSite = $formClass === \Omeka\Form\SiteSettingsForm::class;
        $key = $isSite ? 'site_settings' : 'settings';

        $config = $services->get('Config');
        // Seed with the known core defaults, then let modules declare theirs.
        $defaults = $config['easyadmin']['core_settings_defaults'][$key] ?? [];
        foreach ($config as $space) {
            if (is_array($space) && !empty($space[$key]) && is_array($space[$key])) {
                $defaults += $space[$key];
            }
        }

        if ($isSite) {
            $settings = $services->get('Omeka\Settings\Site');
            $site = $view->site ?? null;
            $siteId = $site ? $site->id() : null;
        } else {
            $settings = $services->get('Omeka\Settings');
            $siteId = null;
        }

        // Compare the current value to the default according to the type of the
        // default: booleans use a falsey/truthy match (so false, "", null and
        // "0" are equal), arrays use their json, other scalars a string match
        // (so a number and its stored string, 100 and "100", are equal).
        $isSame = function ($current, $default): bool {
            if (is_bool($default)) {
                return filter_var($current, FILTER_VALIDATE_BOOLEAN) === $default;
            }
            // The json is used as soon as one side is an array, because some
            // settings store an array (browse defaults, cached navigation)
            // while their declared default is an empty scalar, and casting an
            // array to string is a warning. An empty array is then the same as
            // an empty default.
            if (is_array($current) || is_array($default)) {
                if (!$current && ($default === null || $default === '' || $default === [])) {
                    return true;
                }
                return json_encode($current) === json_encode($default);
            }
            return (string) $current === (string) $default;
        };

        $status = [];
        foreach ($names as $name) {
            // Settings without a declared default (mostly modules that do not
            // declare theirs) stay unknown.
            if (!array_key_exists($name, $defaults)) {
                $status[$name] = 'unknown';
                continue;
            }
            $default = $defaults[$name];
            // Fall back to the default itself, so an unset value (in particular
            // a boolean false) is reported as default, not modified.
            $current = $isSite
                ? ($siteId ? $settings->get($name, $default, $siteId) : $default)
                : $settings->get($name, $default);
            $status[$name] = $isSame($current, $default) ? 'default' : 'modified';
        }
        return $status;
    }

    public function handleMainSettings(Event $event): void
    {
        $fieldset = $this->handleAnySettings($event, 'settings');
        if (!$fieldset) {
            return;
        }

        // Add data attributes for sortable chosen-selects.
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $sortableElements = [
            'easyadmin_quick_template',
            'easyadmin_quick_class',
        ];
        foreach ($sortableElements as $name) {
            if ($fieldset->has($name)) {
                $element = $fieldset->get($name);
                $element->setAttribute('data-sortable', '1');
                $savedValues = $settings->get($name, []);
                if ($savedValues) {
                    $element->setAttribute('data-values-order', json_encode($savedValues, JSON_UNESCAPED_UNICODE));
                }
            }
        }
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
            $filesData['file'][$key] = json_decode((string) $fileData, true) ?: [];
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
        $disableFileValidation = (bool) $settings->get('disable_file_validation', false);
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

        // The form may be serialized to a compacted json (module AdvancedResourceTemplate)
        // that drops numeric keys of "filesData[file][N]" and reindexes them
        // from 0, while the media keeps original "file_index". So add a cursor.
        $fileEntries = array_values($filesData['file']);
        $bulkCursor = 0;

        $newDataMedias = [];
        foreach ($data['o:media'] as $dataMedia) {
            // Skip null or malformed entries before hydration.
            if (!is_array($dataMedia)) {
                continue;
            }
            $newDataMedias[] = $dataMedia;

            if (empty($dataMedia['o:ingester'])
                || $dataMedia['o:ingester'] !== 'bulk_upload'
            ) {
                continue;
            }

            $fileList = $fileEntries[$bulkCursor++] ?? null;
            if (empty($fileList)) {
                $errorStore->addError('upload', 'There is no uploaded files.'); // @translate
                continue;
            }

            // Convert the media to a list of media for the item hydration.
            // Check errors first to indicate issues to user early.
            $listFiles = [];
            $hasError = false;
            foreach ($fileList as $subIndex => $fileData) {
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
                    if (!$disableFileValidation && !$allowEmptyFiles) {
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
        /** @var \Omeka\Entity\Item $item */
        $item = $event->getParam('response')->getContent();

        $needThumbnailing = false;
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

        // In background (bulk import), run synchronously to avoid registering a
        // shutdown that fires only at end of the long cli process.
        if ($this->isBackgroundProcess()) {
            $job = new \Omeka\Entity\Job();
            $job->setPid(null);
            $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
            $job->setClass(\EasyAdmin\Job\FileDerivativeBulkUpload::class);
            $job->setArgs([
                'item_id' => $item->getId(),
                'ingester' => 'bulk_upload',
                'only_missing' => true,
            ]);
            $job->setOwner($services->get('Omeka\AuthenticationService')->getIdentity());
            $job->setStarted(new \DateTime('now'));
            $jobClass = new \EasyAdmin\Job\FileDerivativeBulkUpload($job, $services);
            $jobClass->perform();
            return;
        }

        // Use deferred job to avoid to run one job by resource.
        if ($services->has('Common\DeferredJobDispatch')) {
            $services->get('Common\DeferredJobDispatch')->defer(
                \EasyAdmin\Job\FileDerivativeBulkUpload::class,
                'easyadmin_bulk_upload_derivatives',
                ['item_id' => $item->getId()],
                function (string $key, array $allParams) {
                    // One job per unique item (job only supports a
                    // single item_id).
                    $seen = [];
                    $dispatches = [];
                    foreach ($allParams as $p) {
                        $id = $p['item_id'];
                        if (isset($seen[$id])) {
                            continue;
                        }
                        $seen[$id] = true;
                        $dispatches[] = [
                            'item_id' => $id,
                            'ingester' => 'bulk_upload',
                            'only_missing' => true,
                        ];
                    }
                    return $dispatches;
                }
            );
        } else {
            $services->get(\Omeka\Job\Dispatcher::class)->dispatch(
                \EasyAdmin\Job\FileDerivativeBulkUpload::class,
                [
                    'item_id' => $item->getId(),
                    'ingester' => 'bulk_upload',
                    'only_missing' => true,
                ]
            );
        }
    }

    public function handleAfterSaveAsset(Event $event): void
    {
        /**
         * @var \Omeka\Entity\Asset $asset
         * @var \Omeka\Api\Request $request
         */
        $request = $event->getParam('request');

        $asset = $event->getParam('response')->getContent();
        if (!$asset) {
            return;
        }

        $fileData = $request->getFileData();
        $hasFileError = !empty($fileData['file']['error'] ?? null);

        $storeOriginalName = $request->getValue('store_original_name');
        if ($storeOriginalName && !$hasFileError) {
            $this->storeAssetWithOriginalName($asset);
        }

        $optimize = $request->getValue('optimize');
        if (!$optimize || $hasFileError) {
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
        } catch (\Throwable $e) {
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
            @unlink($newFilePath);
            return;
        }

        // The temporary new file is moved by store(), so no cleanup needed.

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

    /**
     * Rename the stored asset file to use the original name instead of hash.
     *
     * If the name already exists in the store, a numeric suffix is appended.
     */
    protected function storeAssetWithOriginalName(\Omeka\Entity\Asset $asset): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        // This feature only works with the local file store.
        $store = $services->get('Omeka\File\Store');
        if (!$store instanceof \Omeka\File\Store\Local) {
            return;
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $assetDir = $basePath . '/asset';

        $originalName = $asset->getName();
        if (!$originalName) {
            $logger->warn('Asset #{asset_id}: cannot rename, asset has no name.', ['asset_id' => $asset->getId()]);
            return;
        }

        // Sanitize the name for safe filesystem usage: keep only basename.
        $originalName = basename($originalName);
        // Remove characters that are problematic on filesystems.
        $originalName = preg_replace('/[^\w.\-]/', '_', $originalName);
        if (!strlen($originalName) || $originalName === '.' || $originalName === '..') {
            $logger->warn('Asset #{asset_id}: cannot rename, sanitized name is empty.', ['asset_id' => $asset->getId()]);
            return;
        }

        $extension = $asset->getExtension();
        $currentFilename = $asset->getStorageId()
            . ($extension ? '.' . $extension : '');
        $currentPath = $assetDir . '/' . $currentFilename;
        if (!file_exists($currentPath)) {
            $logger->warn(new PsrMessage(
                'Asset #{asset_id}: cannot rename, file "{filename}" not found in asset directory.', // @translate
                ['asset_id' => $asset->getId(), 'filename' => $currentFilename]
            ));
            return;
        }

        // Build the new storage id from the original name without extension.
        $nameInfo = pathinfo($originalName);
        $baseName = $nameInfo['filename'];
        $nameExt = isset($nameInfo['extension']) ? $nameInfo['extension'] : $extension;

        // If the original name has no filename part (e.g. ".jpg"), use the
        // current storage id.
        if (!strlen($baseName)) {
            return;
        }

        // Limit to 190 characters for database storage_id column.
        $maxLength = 190;
        if (mb_strlen($baseName) > $maxLength) {
            $baseName = mb_substr($baseName, 0, $maxLength);
        }

        // Check uniqueness: if the file already exists, append _1, _2, etc.
        $newStorageId = $baseName;
        $newFilename = $newStorageId . ($nameExt ? '.' . $nameExt : '');
        $newPath = $assetDir . '/' . $newFilename;
        $index = 0;
        while (file_exists($newPath) && $newPath !== $currentPath) {
            $index++;
            $suffix = '_' . $index;
            // Truncate base name to leave room for suffix.
            $truncatedBase = mb_substr($baseName, 0, $maxLength - mb_strlen($suffix));
            $newStorageId = $truncatedBase . $suffix;
            $newFilename = $newStorageId . ($nameExt ? '.' . $nameExt : '');
            $newPath = $assetDir . '/' . $newFilename;
        }

        // Nothing to do if it already has the right name.
        if ($newPath === $currentPath) {
            return;
        }

        if (!@rename($currentPath, $newPath)) {
            $error = error_get_last();
            $logger->err(new PsrMessage(
                'Unable to rename asset file from "{old}" to "{new}": {error}. Check that the web server has write permission on the directory "{dir}".', // @translate
                ['old' => $currentFilename, 'new' => $newFilename, 'error' => $error['message'] ?? 'unknown error', 'dir' => $assetDir]
            ));
            return;
        }

        // Update the entity with the new storage id and extension.
        $asset->setStorageId($newStorageId);
        if ($nameExt && $nameExt !== $extension) {
            $asset->setExtension($nameExt);
        }
        $entityManager = $services->get('Omeka\EntityManager');
        $entityManager->persist($asset);
        $entityManager->flush();
    }

    /**
     * Add search by asset name (issue Common #3).
     *
     * @see https://github.com/Daniel-KM/Omeka-S-module-Common/issues/3
     */
    public function handleAssetSearchQuery(Event $event): void
    {
        $query = $event->getParam('request')->getContent();

        if (empty($query['search'])) {
            return;
        }

        $search = trim((string) $query['search']);
        if ($search === '') {
            return;
        }

        /** @var \Doctrine\ORM\QueryBuilder $qb */
        $qb = $event->getParam('queryBuilder');
        $expr = $qb->expr();

        $qb->andWhere($expr->like(
            'omeka_root.name',
            $qb->createNamedParameter('%' . addcslashes($search, '%_') . '%')
        ));
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

        $element = new \Laminas\Form\Element\Checkbox();
        $element
            ->setName('store_original_name')
            ->setLabel('Store with original name'); // @translate
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
        if ($key !== false) {
            unset($names[$key]);
            $event->setParam('registered_names', $names);
        }
    }
}

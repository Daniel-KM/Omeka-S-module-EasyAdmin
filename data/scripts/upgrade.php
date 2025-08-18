<?php declare(strict_types=1);

namespace EasyAdmin;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

$config = $services->get('Config');
$basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.72')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.72'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

if (version_compare($oldVersion, '3.3.2', '<')) {
    $this->installDirs();
}

if (version_compare($oldVersion, '3.3.5', '<')) {
    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('EasyInstall');
    if ($module) {
        $sql = 'DELETE FROM `module` WHERE `id` = "EasyInstall";';
        $connection->executeStatement($sql);
        $container = new \Laminas\Session\Container('EasyInstall');
        unset($container->addons);
        $message = new PsrMessage(
            'The module replaces the module {module}. The upgrade is automatic.', // @translate
            ['module' => 'Easy Install']
        );
    } else {
        $message = new PsrMessage(
            'It’s now possible to install {link}modules and themes{link_end}.', // @translate
            [
                // Route easy-admin is not available during upgrade.
                'link' => sprintf('<a href="%s">', $url('admin/default', ['controller' => 'easy-admin', 'action' => 'addons'])),
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
    }
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.6', '<')) {
    $sqlFile = $this->modulePath() . '/data/install/schema.sql';
    if (!$this->checkNewTablesFromFile($sqlFile)) {
        $message = new PsrMessage(
            $translator->translate('This module cannot install its tables, because they exist already. Try to remove them first.') // @translate
        );
        throw new ModuleCannotInstallException((string) $message);
    }
    $this->execSqlFromFile($sqlFile);

    $settings->set('easyadmin_content_lock', true);

    $message = new PsrMessage(
        'A anti-concurrent editing feature has been added: when a user opens a resource for edition, others users cannot edit it until submission.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'This option can be enabled/disabled in {link}main settings{link_end}.', // @translate
        [
            'iink' => sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easy-admin'])),
            'iink_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.7', '<')) {
    $settings->set('easyadmin_content_lock_duration', 86400);

    $message = new PsrMessage('The content locks are removed after 24h by default.'); // @translate
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'This option can be enabled/disabled in {link}main settings{link_end}.', // @translate
        [
            'link' => sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easy-admin'])),
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A task has been added to manage precise xml media types, for example "application/alto+xml" instead of "text/xml".' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'This task can be run via the main {link}menu{link_end}.', // @translate
        [
            'link' => sprintf('<a href="%s">', $url('admin', [], true) . '/easy-admin/check-and-fix#files_database'),
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.8', '<')) {
    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('BulkCheck');
    if ($module) {
        $sql = 'DELETE FROM `module` WHERE `id` = "BulkCheck";';
        $connection->executeStatement($sql);
        $container = new \Laminas\Session\Container('BulkCheck');
        unset($container->addons);
        $message = new PsrMessage(
            'The module replaces the module {module}. The upgrade is automatic.', // @translate
            ['module' => 'Bulk Check']
        );
        $messenger->addSuccess($message);
    }

    $maintenanceStatus = $settings->get('maintenance_status', false) ? 'no' : 'public';
    $maintenanceText = $settings->get('maintenance_text') ?: $translate('This site is down for maintenance. Please contact the site administrator for more information.'); // @translate
    $settings->set('easyadmin_maintenance_status', $maintenanceStatus);
    $settings->set('easyadmin_maintenance_text', $maintenanceText); // @translate

    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('Maintenance');
    if ($module) {
        $sql = 'DELETE FROM `module` WHERE `id` = "Maintenance";';
        $connection->executeStatement($sql);
        $message = new PsrMessage(
            'The module replaces the module {module}. The upgrade is automatic.', // @translate
            ['module' => 'Maintenance']
        );
    } else {
        $message = new PsrMessage(
            'It’s now possible to set the site in {link}maintenance mode{link_end} for public or users.', // @translate
            [
                'link' => sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easy-admin'])),
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
    }
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.8', '<')) {
    $settings->set('easyadmin_interface', ['resource_public_view']);
    $message = new PsrMessage(
        'An {link}soption{link_end} allows to display a link from the resource admin page to the public page.', // @translate
        [
            'link' => sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easyadmin_interface'])),
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.9.2', '<')) {
    /** @var \Omeka\Module\Manager $moduleManager */
    $modules = [
        'BulkCheck',
        'Maintenance',
    ];
    $moduleManager = $services->get('Omeka\ModuleManager');
    foreach ($modules as $moduleName) {
        $module = $moduleManager->getModule($moduleName);
        $sql = 'DELETE FROM `module` WHERE `id` = "' . $moduleName . '";';
        $connection->executeStatement($sql);
        $sql = 'DELETE FROM `setting` WHERE `id` LIKE "' . strtolower($moduleName) . '_%";';
        $connection->executeStatement($sql);
        $sql = 'DELETE FROM `site_setting` WHERE `id` LIKE "' . strtolower($moduleName) . '_%";';
        $connection->executeStatement($sql);
        if ($module) {
            $message = new PsrMessage(
                'The module "{module}" was upgraded by module "{module_2}" and uninstalled.', // @translate
                ['module' => $module, 'module_2' => 'Easy Admin']
            );
            $messenger->addWarning($message);
        }
    }
}

if (version_compare($oldVersion, '3.4.11', '<')) {
    $settings->set('easyadmin_interface', ['resource_public_view']);
    $message = new PsrMessage('An {link}option{link_end} allows to display links to previous and next resources.', // @translate
        [
            'link' => sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easyadmin_interface'])),
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.12', '<')) {
    // Reset the session for browse page, managed differently.
    $session = new \Laminas\Session\Container('EasyAdmin');
    $session->lastBrowsePage = [];
    $session->lastQuery = [];
}

if (version_compare($oldVersion, '3.4.13', '<')) {
    $message = new PsrMessage(
        'The script for tasks was updated and option `--job` is now passed by default. Check your cron tasks if you use it.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.14', '<')) {
    $message = new PsrMessage(
        'New tasks were added to set the primary media of all items and to check resources.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.15', '<')) {
    if (!$this->checkDestinationDir($basePath . '/backup')) {
        $message = new \Omeka\Stdlib\Message(
            'The directory "{dir}" is not writeable.', // @translate
            ['dir' => $basePath]
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    $message = new PsrMessage(
        'A new task allows to backup Omeka installation files (without directory /files).' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A new task allows to clear php caches (code and data), in particular after an update or direct modification of code.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.18', '<')) {
    $importLocalPath = $settings->get('bulkimport_local_path');
    if (!$importLocalPath || !file_exists($importLocalPath) || !is_dir($importLocalPath) || !is_writeable($importLocalPath)) {
        $importLocalPath = $basePath . '/import';
    }
    $settings->set('easyadmin_local_path', $importLocalPath);
    $localPath = $settings->get('easyadmin_local_path');
    if (!$localPath || $localPath === $basePath || $localPath === $basePath . '/') {
        $settings->set('easyadmin_local_path', $basePath . '/import');
        $localPath = $settings->get('easyadmin_local_path');
    }
    $settings->set('easyadmin_allow_empty_files', (bool) $settings->get('bulkimport_allow_empty_files'));
    $settings->set('easyadmin_addon_notify_version_inactive', true);
    $settings->set('easyadmin_addon_notify_version_dev', false);

    if (!$this->checkDestinationDir($localPath)) {
        $message = new PsrMessage(
            'The directory "{dir}" is not writeable.', // @translate
            ['dir' => $localPath]
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
    }

    $message = new PsrMessage(
        'A new task allows to loop resources, for example to manage a new setting in template or in parameters via triggers.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'It is now possible to bulk upload files without any server limit (file size, post size, number of files) in item form and in a separate form (feature moved from module {link_module}Bulk Import{link_end}, fix {link_github}omeka/omeka-s #1785{link_end}).', // @translate
        [
            'link_module' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport" target="_blank" rel="noopener">',
            'link_end' => '</a>',
            'link_github' => '<a href="https://github.com/omeka/omeka-s/issues/1785" target="_blank" rel="noopener">',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A new option allows to notify new version of inactive modules.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.21', '<')) {
    $message = new PsrMessage(
        'It is now possible to optimize assets for web.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.22', '<')) {
    // Refresh the list of addons.
    // The plugin is not available during install.
    require_once __DIR__ . '/../../src/Mvc/Controller/Plugin/Addons.php';
    $addons = new \EasyAdmin\Mvc\Controller\Plugin\Addons(
        $plugins->get('api'),
        $services->get('Omeka\HttpClient'),
        $plugins->get('messenger'),
    );
    try {
        $addons->getAddons(true);
    } catch (\Exception $e) {
        // Addons may fail behind a proxy without internet access.
        $container = new \Laminas\Session\Container('EasyAdmin');
        unset($container->addons);
    }

    $message = new PsrMessage(
        'It is now possible to install a curated selection of modules.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.27', '<')) {
    $message = new PsrMessage(
        'It is now possible to upload and delete local files in a configurated directory.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.28', '<')) {
    $message = new PsrMessage(
        'To allow to upload files in a specific path outside of directory files/, update the file local.config.php.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.29', '<')) {
    $tasks = $settings->get('easyadmin_cron_tasks');
    if ($tasks === null) {
        $settings->set('easyadmin_cron_tasks', ['session_8']);
    }

    $message = new PsrMessage(
        'It is now possible to define regular tasks to remove sessions regularly. A first cleaning of old ones is launched.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.31', '<')) {
    // If the module Content Lock is installed, do not update settings and do
    // not remove tables.
    $hasLockEdit = $this->isModuleVersionAtLeast('LockEdit', '3.4.0');
    if (!$hasLockEdit) {
        $settings->set('lockedit_duration', $settings->get('easyadmin_content_lock_duration', 14400));
        $sql = <<<'SQL'
            SET foreign_key_checks = 0;
            DROP TABLE IF EXISTS content_lock;
            SQL;
        $connection->executeStatement($sql);
    } else {
        // Simplify upgrade or migration.
        $connection->executeStatement('TRUNCATE TABLE content_lock;');
    }

    $settings->delete('easyadmin_content_lock');
    $settings->delete('easyadmin_content_lock_duration');

    $message = new PsrMessage(
        'The feature to lock concurrent editing was moved to the new module {link}Lock Edit{link_end}. You should install it if you need it.', // @translate
        [
            'link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-LockEdit" _target="blank" rel="noopener">',
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'A new setting allows the reviewer to delete any resource.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A new setting allows to add a button to quick create a resource with a specific template.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.32', '<')) {
    $message = new PsrMessage(
        'A new setting allows to define the name of the administrator.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A new setting allows to define a no-reply email for automatic messages and notifications.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.33', '<')) {
    $this->installDirs();

    $directories = array_filter(array_unique([
        $basePath . '/backup',
        $basePath . '/check',
        $basePath . '/import',
        $settings->get('easyadmin_local_path') ?: $basePath . '/import',
    ]));
    sort($directories);
    $settings->set('easyadmin_local_paths', $directories);

    $message = new PsrMessage(
        'A new setting allows to define a list of directories available for browsing.' // @translate
    );
    $messenger->addSuccess($message);
}

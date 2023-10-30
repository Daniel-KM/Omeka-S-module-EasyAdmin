<?php declare(strict_types=1);

namespace EasyAdmin;

use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

$config = $services->get('Config');
$basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

if (version_compare($oldVersion, '3.3.2', '<')) {
    $this->installDir();
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
        $message = new Message(
            'The module replaces the module %s. The upgrade is automatic.', // @translate
            'Easy Install'
        );
    } else {
        $message = new Message('It’s now possible to install %1$smodules and themes%2$s.', // @translate
            // Route easy-admin is not available during upgrade.
            sprintf('<a href="%s">', $url('admin/default', ['controller' => 'easy-admin', 'action' => 'addons'])),
            '</a>'
        );
        $message->setEscapeHtml(false);
    }
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.6', '<')) {
    $sqlFile = $this->modulePath() . '/data/install/schema.sql';
    if (!$this->checkNewTablesFromFile($sqlFile)) {
        $translator = $services->get('MvcTranslator');
        $message = new Message(
            $translator->translate('This module cannot install its tables, because they exist already. Try to remove them first.') // @translate
        );
        throw new ModuleCannotInstallException((string) $message);
    }
    $this->execSqlFromFile($sqlFile);

    $settings->set('easyadmin_content_lock', true);

    $message = new Message('A anti-concurrent editing feature has been added: when a user opens a resource for edition, others users cannot edit it until submission.'); // @translate
    $messenger->addSuccess($message);
    $message = new Message('This option can be enabled/disabled in %1$smain settings%2$s.', // @translate
        sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easy-admin'])),
        '</a>'
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.7', '<')) {
    $settings->set('easyadmin_content_lock_duration', 86400);

    $message = new Message('The content locks are removed after 24h by default.'); // @translate
    $messenger->addSuccess($message);
    $message = new Message('This option can be enabled/disabled in %1$smain settings%2$s.', // @translate
        sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easy-admin'])),
        '</a>'
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);

    $message = new Message('A task has been added to manage precise xml media types, for example "application/alto+xml" instead of "text/xml".'); // @translate
    $messenger->addSuccess($message);
    $message = new Message('This task can be run via the main %1$smenu%2$s.', // @translate
        sprintf('<a href="%s">', $url('admin', [], true) . '/easy-admin/check-and-fix#files_database'),
        '</a>'
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
        $message = new Message(
            'The module replaces the module %s. The upgrade is automatic.', // @translate
            'Bulk Check'
        );
        $messenger->addSuccess($message);
    }

    $maintenanceStatus = $settings->get('maintenance_status', false) ? 'no' : 'public';
    $maintenanceText = $settings->get('maintenance_text') ?: $services->get('MvcTranslator')->translate('This site is down for maintenance. Please contact the site administrator for more information.'); // @translate
    $settings->set('easyadmin_maintenance_status', $maintenanceStatus);
    $settings->set('easyadmin_maintenance_text', $maintenanceText); // @translate

    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('Maintenance');
    if ($module) {
        $sql = 'DELETE FROM `module` WHERE `id` = "Maintenance";';
        $connection->executeStatement($sql);
        $message = new Message(
            'The module replaces the module %s. The upgrade is automatic.', // @translate
            'Maintenance'
        );
    } else {
        $message = new Message('It’s now possible to set the site in %1$smaintenance mode%2$s for public or users.', // @translate
            sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easy-admin'])),
            '</a>'
        );
        $message->setEscapeHtml(false);
    }
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.8', '<')) {
    $settings->set('easyadmin_interface', ['resource_public_view']);
    $message = new Message('An %1$soption%2$s allows to display a link from the resource admin page to the public page.', // @translate
        sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easyadmin_interface'])),
        '</a>'
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
            $message = new \Omeka\Stdlib\Message(
                'The module "%1$s" was upgraded by module "%2$s" and uninstalled.', // @translate
                $module, 'Easy Admin'
            );
            $messenger->addWarning($message);
        }
    }
}

if (version_compare($oldVersion, '3.4.11', '<')) {
    $settings->set('easyadmin_interface', ['resource_public_view']);
    $message = new Message('An %1$soption%2$s allows to display links to previous and next resources.', // @translate
        sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'easyadmin_interface'])),
        '</a>'
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
    $message = new Message(
        'The script for tasks was updated and option `--job` is now passed by default. Check your cron tasks if you use it.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.14', '<')) {
    $message = new Message(
        'New tasks were added to set the primary media of all items and to check resources.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.15', '<')) {
    if (!$this->checkDestinationDir($basePath . '/backup')) {
        $message = new \Omeka\Stdlib\Message(
            'The directory "%s" is not writeable.', // @translate
            $basePath
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
    }

    $message = new Message(
        'A new task allow to backup Omeka installation files (without directory /files).' // @translate
    );
    $messenger->addSuccess($message);

    $message = new Message(
        'A new task allow to clear php caches (code and data), in particular after an update or direct modification of code.' // @translate
    );
    $messenger->addSuccess($message);
}

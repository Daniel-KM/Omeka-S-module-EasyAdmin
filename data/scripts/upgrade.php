<?php declare(strict_types=1);

namespace EasyAdmin;

use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\View\Helper\Url $url
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$services = $serviceLocator;
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
// $api = $plugins->get('api');
// $config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
// $entityManager = $services->get('Omeka\EntityManager');

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
        $message = new Message('The module replaces the module Easy Install. The upgrade is automatic.'); // @translate
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

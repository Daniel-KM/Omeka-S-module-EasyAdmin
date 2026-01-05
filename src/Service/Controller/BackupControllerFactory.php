<?php declare(strict_types=1);

namespace EasyAdmin\Service\Controller;

use EasyAdmin\Controller\Admin\BackupController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BackupControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        return new BackupController($basePath);
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Service\Controller;

use EasyAdmin\Controller\Admin\FileManagerController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class FileManagerControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempDir = $services->get('Config')['temp_dir'] ?: sys_get_temp_dir();
        return new FileManagerController(
            $services->get('Omeka\Acl'),
            rtrim($tempDir, '/\\')
        );
    }
}

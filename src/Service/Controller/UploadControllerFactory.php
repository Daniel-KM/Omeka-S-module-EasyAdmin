<?php declare(strict_types=1);

namespace EasyAdmin\Service\Controller;

use EasyAdmin\Controller\UploadController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class UploadControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        $tempDir = $config['temp_dir'] ?: sys_get_temp_dir();
        return new UploadController(
            $services->get(\Omeka\File\TempFileFactory::class),
            $services->get(\Omeka\File\Validator::class),
            (bool) $config['easyadmin']['config']['easyadmin_local_path_any'],
            $basePath,
            rtrim($tempDir, '/\\')
        );
    }
}

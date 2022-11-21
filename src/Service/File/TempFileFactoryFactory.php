<?php declare(strict_types=1);

namespace EasyAdmin\Service\File;

use EasyAdmin\File\TempFileFactory;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TempFileFactoryFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = new TempFileFactory($services);
        return $tempFileFactory
            ->setSpecifyMediaType($services->get('ControllerPluginManager')->get('specifyMediaType'));
    }
}

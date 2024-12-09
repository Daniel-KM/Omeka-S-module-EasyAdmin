<?php declare(strict_types=1);

namespace EasyAdmin\Service\ControllerPlugin;

use EasyAdmin\Mvc\Controller\Plugin\Addons;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AddonsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Plugins may not be available in background job.
        $plugins = $services->get('ControllerPluginManager');

        return new Addons(
            $plugins->get('api'),
            $services->get('Omeka\HttpClient'),
            $plugins->get('messenger')
        );
    }
}

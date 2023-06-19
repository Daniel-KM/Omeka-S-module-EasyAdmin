<?php declare(strict_types=1);

namespace EasyAdmin\Service\ViewHelper;

use EasyAdmin\View\Helper\BrowsePreviousNext;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BrowsePreviousNextFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new BrowsePreviousNext(
            $services->get('Omeka\ApiAdapterManager'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\EntityManager'),
            $plugins->has('searchResources') ? $plugins->get('searchResources') : null
        );
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Service\Delegator;

use EasyAdmin\Delegator\AssetAdapterDelegator;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use Psr\Container\ContainerInterface;

class AssetAdapterDelegatorFactory implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name, callable $callback, ?array $options = null)
    {
        // The callback returns the original AssetAdapter.
        // We return our delegator which extends AssetAdapter.
        // The delegator will be injected with services via setServiceLocator().
        return new AssetAdapterDelegator();
    }
}

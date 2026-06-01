<?php declare(strict_types=1);

namespace EasyAdmin\Service\File\Store;

use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use Omeka\File\Store\Local;
use Psr\Container\ContainerInterface;

class LocalStoreBaseUriDelegatorFactory implements DelegatorFactoryInterface
{
    /**
     * Override the base URI of the local file store with the EasyAdmin setting,
     * keeping the base path local. This allows to serve file URLs from a remote
     * server (for example production) while working on a local copy of the
     * database without the files. Uploads and derivatives are still stored
     * locally; only the generated URLs are affected.
     */
    public function __invoke(ContainerInterface $container, $name, callable $callback, ?array $options = null)
    {
        $store = $callback();
        if (!$store instanceof Local) {
            return $store;
        }

        // Settings may be unavailable during early bootstrap or install.
        try {
            $baseUri = trim((string) $container->get('Omeka\Settings')->get('easyadmin_local_store_base_uri', ''));
        } catch (\Throwable $e) {
            return $store;
        }
        if ($baseUri === '') {
            return $store;
        }

        $property = new \ReflectionProperty(Local::class, 'baseUri');
        $property->setAccessible(true);
        $property->setValue($store, rtrim($baseUri, '/'));

        return $store;
    }
}

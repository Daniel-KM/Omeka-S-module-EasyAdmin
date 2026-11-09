<?php declare(strict_types=1);

namespace EasyAdmin\Service\File\Store;

use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use EasyAdmin\File\Store\LocalFakeImages;
use EasyAdmin\Job\FakeImages;
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
     *
     * The images may be replaced by placeholders or by images of Wikimedia
     * Commons stored locally, when the remote server is not available or
     * protected.
     */
    public function __invoke(ContainerInterface $container, $name, callable $callback, ?array $options = null)
    {
        $store = $callback();
        if (!$store instanceof Local) {
            return $store;
        }

        // Settings may be unavailable during early bootstrap or install.
        try {
            $settings = $container->get('Omeka\Settings');
            $baseUri = trim((string) $settings->get('easyadmin_local_store_base_uri', ''));
            $fakeImages = (string) $settings->get('easyadmin_local_store_fake_images', '');
        } catch (\Throwable $e) {
            return $store;
        }

        $property = new \ReflectionProperty(Local::class, 'baseUri');
        $property->setAccessible(true);
        $localBaseUri = (string) $property->getValue($store);
        if ($baseUri !== '') {
            $property->setValue($store, rtrim($baseUri, '/'));
        }

        if ($fakeImages === 'placeholder') {
            return LocalFakeImages::fromStore($store, 'placeholder');
        }
        if ($fakeImages === 'wikimedia') {
            return LocalFakeImages::fromStore($store, 'wikimedia', $this->wikimediaUrls($container, $settings, $localBaseUri));
        }

        return $store;
    }

    /**
     * Get the local urls of the images of Wikimedia Commons of the keyword.
     *
     * The images are downloaded once into "files/" by a background job, so the
     * pages are not slowed down. Meanwhile, or when they cannot be fetched, the
     * placeholders are used and the job is retried one hour later.
     *
     * @return string[]
     */
    protected function wikimediaUrls(ContainerInterface $container, $settings, string $localBaseUri): array
    {
        $keyword = trim((string) $settings->get('easyadmin_local_store_wikimedia_keyword', ''));
        $cache = $settings->get(FakeImages::SETTING, []);
        if (is_array($cache) && ($cache['keyword'] ?? null) === $keyword) {
            if (!empty($cache['files'])) {
                return array_map(
                    fn ($file) => $localBaseUri . '/' . FakeImages::DIRECTORY . '/' . $file,
                    $cache['files']
                );
            }
            if (($cache['failed'] ?? $cache['pending'] ?? 0) > time() - 3600) {
                return [];
            }
        }

        // Set the pending state first, else each request would dispatch a job.
        $settings->set(FakeImages::SETTING, ['keyword' => $keyword, 'pending' => time()]);
        try {
            $container->get(\Omeka\Job\Dispatcher::class)->dispatch(FakeImages::class, ['keyword' => $keyword]);
        } catch (\Throwable $e) {
            $settings->set(FakeImages::SETTING, ['keyword' => $keyword, 'failed' => time()]);
        }
        return [];
    }
}

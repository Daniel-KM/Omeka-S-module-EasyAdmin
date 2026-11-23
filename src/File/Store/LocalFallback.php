<?php declare(strict_types=1);

namespace EasyAdmin\File\Store;

use Omeka\File\Store\Local;

/**
 * Local file store that serves the local files, else the remote ones.
 *
 * On a copy of the database without the files, the files of the original site
 * are served from its server, while the files created on the copy (uploads and
 * derivatives) are served locally.
 */
class LocalFallback extends Local
{
    /**
     * @var string
     */
    protected $localBaseUri;

    /**
     * Create the store from another one, with the same paths and logger.
     *
     * The base uri of the store is the remote one.
     */
    public static function fromStore(Local $store, string $localBaseUri): self
    {
        $fallbackStore = new static($store->basePath, $store->baseUri, $store->logger);
        $fallbackStore->localBaseUri = rtrim($localBaseUri, '/');
        return $fallbackStore;
    }

    public function getUri($storagePath)
    {
        return $this->isLocal((string) $storagePath)
            ? $this->localBaseUri . '/' . $storagePath
            : parent::getUri($storagePath);
    }

    protected function isLocal(string $storagePath): bool
    {
        return $this->basePath
            && strpos($storagePath, '..') === false
            && is_file($this->basePath . '/' . $storagePath);
    }
}

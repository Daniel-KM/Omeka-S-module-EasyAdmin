<?php declare(strict_types=1);

namespace EasyAdmin\File\Store;

use Omeka\File\Store\Local;

/**
 * Local file store that serves fake images instead of the image files.
 *
 * It is useful to work on a copy of the database without the files, when the
 * server of the files is not available or protected. The other files and the
 * storage are not modified.
 */
class LocalFakeImages extends Local
{
    /**
     * Maximum width of each type of file.
     *
     * Wikimedia serves only some standard widths of thumbnails, and not wider
     * than the image, so the original is not larger than the large one.
     */
    const WIDTHS = [
        'original' => 960,
        'large' => 960,
        'medium' => 500,
        'square' => 250,
    ];

    const IMAGE_EXTENSIONS = [
        'bmp', 'gif', 'jp2', 'jpeg', 'jpg', 'png', 'svg', 'tif', 'tiff', 'webp',
    ];

    /**
     * @var string "placeholder" or "wikimedia".
     */
    protected $mode;

    /**
     * @var string[] Urls of thumbnails of Wikimedia Commons.
     */
    protected $wikimediaUrls;

    /**
     * Create the store from another one, with the same paths and logger.
     */
    public static function fromStore(Local $store, string $mode, array $wikimediaUrls = []): self
    {
        $fakeStore = new self($store->basePath, $store->baseUri, $store->logger);
        $fakeStore->mode = $mode;
        $fakeStore->wikimediaUrls = array_values($wikimediaUrls);
        return $fakeStore;
    }

    public function getUri($storagePath)
    {
        $type = strtok((string) $storagePath, '/');
        $extension = strtolower(pathinfo((string) $storagePath, PATHINFO_EXTENSION));
        if (!isset(self::WIDTHS[$type])
            || !in_array($extension, self::IMAGE_EXTENSIONS, true)
        ) {
            return parent::getUri($storagePath);
        }

        $width = self::WIDTHS[$type];
        if ($this->mode === 'wikimedia' && $this->wikimediaUrls) {
            // The same file has always the same image.
            $url = $this->wikimediaUrls[crc32(pathinfo((string) $storagePath, PATHINFO_FILENAME)) % count($this->wikimediaUrls)];
            // A small image has no thumbnail.
            return strpos($url, '/thumb/') === false
                ? $url
                : preg_replace('~/\d+px-~', '/' . $width . 'px-', $url, 1);
        }

        return $this->placeholder($width, $type === 'square' ? $width : (int) ($width * 3 / 4));
    }

    /**
     * Get a grey svg image as data uri, without any request.
     */
    protected function placeholder(int $width, int $height): string
    {
        $fontSize = max(12, (int) ($width / 16));
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">'
            . '<rect width="100%%" height="100%%" fill="#d6d6d6"/>'
            . '<text x="50%%" y="50%%" font-family="sans-serif" font-size="%3$d" fill="#707070" text-anchor="middle" dominant-baseline="middle">%1$d × %2$d</text>'
            . '</svg>',
            $width,
            $height,
            $fontSize
        );
        return 'data:image/svg+xml,' . rawurlencode($svg);
    }
}

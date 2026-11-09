<?php declare(strict_types=1);

namespace EasyAdminTest\File\Store;

use EasyAdmin\File\Store\LocalFakeImages;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Omeka\File\Store\Local;
use PHPUnit\Framework\TestCase;

class LocalFakeImagesTest extends TestCase
{
    protected function local(): Local
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());
        return new Local(sys_get_temp_dir(), 'https://example.org/files', $logger);
    }

    public function testStoreKeepsPathsAndClass(): void
    {
        $store = LocalFakeImages::fromStore($this->local(), 'placeholder');
        $this->assertInstanceOf(Local::class, $store);
        $this->assertSame(realpath(sys_get_temp_dir()) . '/original/a.jpg', $store->getLocalPath('original/a.jpg'));
    }

    public function testPlaceholderForImages(): void
    {
        $store = LocalFakeImages::fromStore($this->local(), 'placeholder');
        $uri = $store->getUri('large/abc.jpg');
        $this->assertStringStartsWith('data:image/svg+xml,', $uri);
        $this->assertStringContainsString(rawurlencode('960 × 720'), $uri);
        $this->assertStringContainsString(rawurlencode('250 × 250'), $store->getUri('square/abc.jpg'));
    }

    public function testOtherFilesKeepTheirUrl(): void
    {
        $store = LocalFakeImages::fromStore($this->local(), 'placeholder');
        $this->assertSame('https://example.org/files/original/abc.pdf', $store->getUri('original/abc.pdf'));
        $this->assertSame('https://example.org/files/asset/logo.png', $store->getUri('asset/logo.png'));
    }

    public function testWikimediaImagesAreStableAndSized(): void
    {
        $urls = [
            'https://thumb.wikimedia.org/wikipedia/commons/thumb/a/ab/A.jpg/960px-A.jpg',
            'https://thumb.wikimedia.org/wikipedia/commons/thumb/c/cd/B.jpg/960px-B.jpg',
        ];
        $store = LocalFakeImages::fromStore($this->local(), 'wikimedia', $urls);
        $large = $store->getUri('large/abc.jpg');
        $this->assertContains($large, $urls);
        $this->assertSame($large, $store->getUri('large/abc.jpg'));
        $this->assertSame(str_replace('/960px-', '/250px-', $large), $store->getUri('square/abc.jpg'));
    }

    public function testWikimediaSmallImageWithoutThumbnail(): void
    {
        $url = 'https://upload.wikimedia.org/wikipedia/commons/e/e7/Small.jpg';
        $store = LocalFakeImages::fromStore($this->local(), 'wikimedia', [$url]);
        $this->assertSame($url, $store->getUri('square/abc.jpg'));
    }

    public function testWikimediaWithoutImagesUsesPlaceholders(): void
    {
        $store = LocalFakeImages::fromStore($this->local(), 'wikimedia', []);
        $this->assertStringStartsWith('data:image/svg+xml,', $store->getUri('large/abc.jpg'));
    }
}

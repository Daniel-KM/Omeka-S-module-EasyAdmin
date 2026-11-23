<?php declare(strict_types=1);

namespace EasyAdminTest\File\Store;

use EasyAdmin\File\Store\LocalFakeImages;
use EasyAdmin\File\Store\LocalFallback;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Omeka\File\Store\Local;
use PHPUnit\Framework\TestCase;

class LocalFallbackTest extends TestCase
{
    /**
     * @var string
     */
    protected $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/easyadmin-fallback-' . uniqid();
        mkdir($this->basePath . '/large', 0777, true);
        touch($this->basePath . '/large/local.jpg');
    }

    protected function tearDown(): void
    {
        unlink($this->basePath . '/large/local.jpg');
        rmdir($this->basePath . '/large');
        rmdir($this->basePath);
    }

    protected function remote(): Local
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());
        return new Local($this->basePath, 'https://example.org/files', $logger);
    }

    public function testLocalFileIsServedLocally(): void
    {
        $store = LocalFallback::fromStore($this->remote(), 'https://copy.example.org/files/');
        $this->assertSame('https://copy.example.org/files/large/local.jpg', $store->getUri('large/local.jpg'));
    }

    public function testMissingFileIsServedRemotely(): void
    {
        $store = LocalFallback::fromStore($this->remote(), 'https://copy.example.org/files');
        $this->assertSame('https://example.org/files/large/missing.jpg', $store->getUri('large/missing.jpg'));
        $this->assertSame('https://example.org/files/large/../large/local.jpg', $store->getUri('large/../large/local.jpg'));
    }

    public function testStoreKeepsPaths(): void
    {
        $store = LocalFallback::fromStore($this->remote(), 'https://copy.example.org/files');
        $this->assertSame(realpath($this->basePath) . '/large/local.jpg', $store->getLocalPath('large/local.jpg'));
    }

    public function testFakeImagesKeepLocalFiles(): void
    {
        $store = LocalFakeImages::fromStore($this->remote(), 'placeholder', [], 'https://copy.example.org/files');
        $this->assertSame('https://copy.example.org/files/large/local.jpg', $store->getUri('large/local.jpg'));
        $this->assertStringStartsWith('data:image/svg+xml,', $store->getUri('large/missing.jpg'));
        $this->assertSame('https://example.org/files/original/missing.pdf', $store->getUri('original/missing.pdf'));
    }
}

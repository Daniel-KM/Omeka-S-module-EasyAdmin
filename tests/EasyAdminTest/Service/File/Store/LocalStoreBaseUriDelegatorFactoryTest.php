<?php declare(strict_types=1);

namespace EasyAdminTest\Service\File\Store;

use EasyAdmin\Job\FakeImages;
use EasyAdmin\Service\File\Store\LocalStoreBaseUriDelegatorFactory;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests the state of the local images of Wikimedia Commons, without request.
 */
class LocalStoreBaseUriDelegatorFactoryTest extends TestCase
{
    protected function settings(array $values): object
    {
        return new class($values) {
            public array $values;

            public function __construct(array $values)
            {
                $this->values = $values;
            }

            public function get($name, $default = null)
            {
                return $this->values[$name] ?? $default;
            }

            public function set($name, $value): void
            {
                $this->values[$name] = $value;
            }
        };
    }

    protected function wikimediaUrls(object $settings): array
    {
        $method = new \ReflectionMethod(LocalStoreBaseUriDelegatorFactory::class, 'wikimediaUrls');
        $method->setAccessible(true);
        // No service: no job may be dispatched.
        return $method->invoke(new LocalStoreBaseUriDelegatorFactory(), new ServiceManager(), $settings, 'https://example.org/files');
    }

    public function testLocalUrlsOfTheSameKeyword(): void
    {
        $settings = $this->settings([
            'easyadmin_local_store_wikimedia_keyword' => 'lighthouse',
            FakeImages::SETTING => ['keyword' => 'lighthouse', 'files' => ['lighthouse/01.jpg']],
        ]);
        $this->assertSame(['https://example.org/files/fake/lighthouse/01.jpg'], $this->wikimediaUrls($settings));
    }

    public function testPendingOrRecentFailureIsNotRetried(): void
    {
        foreach (['pending', 'failed'] as $state) {
            $settings = $this->settings([
                'easyadmin_local_store_wikimedia_keyword' => 'lighthouse',
                FakeImages::SETTING => ['keyword' => 'lighthouse', $state => time() - 60],
            ]);
            $this->assertSame([], $this->wikimediaUrls($settings));
            $this->assertArrayHasKey($state, $settings->values[FakeImages::SETTING]);
        }
    }

    public function testOtherKeywordDispatchesAJob(): void
    {
        $settings = $this->settings([
            'easyadmin_local_store_wikimedia_keyword' => 'flower',
            FakeImages::SETTING => ['keyword' => 'lighthouse', 'files' => ['lighthouse/01.jpg']],
        ]);
        // The dispatcher is missing, so the job is marked as failed.
        $this->assertSame([], $this->wikimediaUrls($settings));
        $this->assertSame('flower', $settings->values[FakeImages::SETTING]['keyword']);
        $this->assertArrayHasKey('failed', $settings->values[FakeImages::SETTING]);
    }
}

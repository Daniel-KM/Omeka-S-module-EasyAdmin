<?php declare(strict_types=1);

namespace EasyAdminTest\Mvc\Controller\Plugin;

use EasyAdmin\Mvc\Controller\Plugin\Addons;
use PHPUnit\Framework\TestCase;

/**
 * An addon listed by omeka.org and by the github list is returned with the
 * greatest version, whatever the list.
 */
class AddonsDataFromNamespaceTest extends TestCase
{
    protected function addons(array $list): Addons
    {
        $addons = (new \ReflectionClass(Addons::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(Addons::class, 'addons');
        $property->setAccessible(true);
        $property->setValue($addons, $list);
        return $addons;
    }

    protected function entry(string $url, string $version): array
    {
        return [$url => ['url' => $url, 'dir' => 'Mapping', 'name' => 'Mapping', 'version' => $version]];
    }

    public function testGreatestVersionOfBothLists(): void
    {
        $addons = $this->addons([
            'omekamodule' => $this->entry('https://github.com/omeka-s-modules/Mapping', '2.4.0'),
            'module' => $this->entry('https://github.com/omeka-s-modules/Mapping', '2.3.0'),
            'omekatheme' => [],
            'theme' => [],
        ]);
        $this->assertSame('2.4.0', $addons->dataFromNamespace('Mapping', 'module')['version']);
        $this->assertSame('2.4.0', $addons->dataFromNamespace('Mapping')['version']);
    }

    public function testGithubListMoreRecent(): void
    {
        $addons = $this->addons([
            'omekamodule' => $this->entry('https://github.com/omeka-s-modules/Mapping', '2.3.0'),
            'module' => $this->entry('https://github.com/omeka-s-modules/Mapping', '2.5.0'),
            'omekatheme' => [],
            'theme' => [],
        ]);
        $this->assertSame('2.5.0', $addons->dataFromNamespace('Mapping', 'module')['version']);
        $this->assertSame('2.3.0', $addons->dataFromNamespace('Mapping', 'omekamodule')['version']);
        $this->assertSame([], $addons->dataFromNamespace('Unknown', 'module'));
    }

    /**
     * Two repositories with the same directory are two different addons: a
     * greater version of the other one is not an update.
     */
    public function testOtherRepositoryWithSameDirectoryIsNotUsed(): void
    {
        $addons = $this->addons([
            'omekamodule' => $this->entry('https://github.com/biblibre/omeka-s-module-Search', '0.23.2'),
            'module' => $this->entry('https://github.com/Daniel-KM/Omeka-S-module-Search', '3.5.25.3'),
            'omekatheme' => [],
            'theme' => [],
        ]);
        $this->assertSame('0.23.2', $addons->dataFromNamespace('Mapping', 'module')['version']);
    }

}

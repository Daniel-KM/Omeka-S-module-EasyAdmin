<?php declare(strict_types=1);

namespace EasyAdminTest\View;

use EasyAdmin\Module;
use Laminas\EventManager\Event;
use Omeka\Test\AbstractHttpControllerTestCase;

class ThumbnailLazyLoadingTest extends AbstractHttpControllerTestCase
{
    public function tearDown(): void
    {
        $this->settings()->set('easyadmin_skip_lazy_loading', false);
        parent::tearDown();
    }

    protected function settings(): \Omeka\Settings\Settings
    {
        return $this->getApplication()->getServiceManager()->get('Omeka\Settings');
    }

    protected function attribs(array $attribs): array
    {
        $module = new Module();
        $module->setServiceLocator($this->getApplication()->getServiceManager());
        $event = new Event('view_helper.thumbnail.attribs', null, ['attribs' => $attribs]);
        $module->handleThumbnailAttribs($event);
        return $event->getParam('attribs');
    }

    public function testLoadingIsSetBeforeSource(): void
    {
        $attribs = $this->attribs(['src' => 'https://example.org/square.jpg', 'class' => 'thumb']);
        $this->assertSame(['loading', 'src', 'class'], array_keys($attribs));
        $this->assertSame('lazy', $attribs['loading']);
    }

    public function testLoadingSetByThemeIsKept(): void
    {
        $attribs = $this->attribs(['loading' => 'eager', 'src' => 'https://example.org/square.jpg']);
        $this->assertSame('eager', $attribs['loading']);
    }

    public function testLazyLoadingCanBeSkipped(): void
    {
        $this->settings()->set('easyadmin_skip_lazy_loading', true);
        $attribs = $this->attribs(['src' => 'https://example.org/square.jpg']);
        $this->assertArrayNotHasKey('loading', $attribs);
    }
}

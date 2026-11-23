<?php declare(strict_types=1);

namespace EasyAdminTest\Controller;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Maintenance Controller.
 */
class MaintenanceControllerTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    /**
     * @var string
     */
    protected $maintenanceMode;

    /**
     * @var string
     */
    protected $maintenanceText;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->maintenanceMode = (string) $this->settings()->get('easyadmin_maintenance_mode');
        $this->maintenanceText = (string) $this->settings()->get('easyadmin_maintenance_text');
    }

    public function tearDown(): void
    {
        $this->settings()->set('easyadmin_maintenance_mode', $this->maintenanceMode);
        $this->settings()->set('easyadmin_maintenance_text', $this->maintenanceText);
        $this->logout();
        parent::tearDown();
    }

    /**
     * The controller overrides the one of the core, so the key is the one used
     * by the route "maintenance", and not an admin one.
     */
    public function testMaintenanceControllerOverridesTheCoreOne(): void
    {
        $controllerManager = $this->getService('ControllerManager');

        $this->assertTrue($controllerManager->has('Omeka\Controller\Maintenance'));
        $this->assertInstanceOf(
            \EasyAdmin\Controller\MaintenanceController::class,
            $controllerManager->get('Omeka\Controller\Maintenance')
        );
    }

    public function testPageRedirectsWhenMaintenanceIsOff(): void
    {
        $this->settings()->set('easyadmin_maintenance_mode', '');

        $this->dispatch('/maintenance');

        $this->assertResponseStatusCode(302);
    }

    /**
     * The status 503 keeps the pages indexed by the search engines.
     */
    public function testPageIsUnavailableWhenMaintenanceIsOn(): void
    {
        $this->settings()->set('easyadmin_maintenance_mode', 'public');
        $this->settings()->set('easyadmin_maintenance_text', '');

        $this->dispatch('/maintenance');

        $this->assertResponseStatusCode(503);

        $headers = $this->getResponse()->getHeaders();
        $this->assertEquals('3600', $headers->get('Retry-After')->getFieldValue());
        $this->assertSame('noindex', $headers->get('X-Robots-Tag')->getFieldValue());
        $this->assertQuery('head meta[name="robots"][content="noindex"]');
        $this->assertStringContainsString(
            'This site is down for maintenance',
            $this->getResponse()->getBody()
        );
    }

    public function testTextOfTheSettingsIsDisplayed(): void
    {
        $this->settings()->set('easyadmin_maintenance_mode', 'public');
        $this->settings()->set('easyadmin_maintenance_text', '<p>Back on <strong>monday</strong>.</p>');

        $this->dispatch('/maintenance');

        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('<p>Back on <strong>monday</strong>.</p>', $body);
        $this->assertStringNotContainsString('This site is down for maintenance', $body);
    }
}

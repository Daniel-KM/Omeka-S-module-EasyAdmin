<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the colors of the admin interface.
 */
class AdminColorTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    /**
     * @var string
     */
    protected $adminColor;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->adminColor = (string) $this->settings()->get('easyadmin_admin_color');
    }

    public function tearDown(): void
    {
        $this->settings()->set('easyadmin_admin_color', $this->adminColor);
        $this->logout();
        parent::tearDown();
    }

    public function testNoStyleWithoutColor(): void
    {
        $this->settings()->set('easyadmin_admin_color', '');

        $this->dispatch('/admin');

        $this->assertStringNotContainsString('--easyadmin-admin-color', $this->getResponse()->getBody());
    }

    public function testInvalidColorAddsNoStyle(): void
    {
        $this->settings()->set('easyadmin_admin_color', 'not a color');

        $this->dispatch('/admin');

        $this->assertStringNotContainsString('--easyadmin-admin-color', $this->getResponse()->getBody());
    }

    /**
     * The shades and the color of the texts are derived from the color, so the
     * logo, the buttons, the block of the sites and the borders follow it.
     */
    public function testColorAndItsShadesAreUsed(): void
    {
        $this->settings()->set('easyadmin_admin_color', '#8b1a1a');

        $this->dispatch('/admin');
        $body = $this->getResponse()->getBody();

        // The variables of the core, used when it exposes its palette.
        $this->assertStringContainsString('--omeka-admin-color: #8b1a1a;', $body);
        $this->assertStringContainsString('--omeka-admin-color-shade-15: #4b0e0e;', $body);
        $this->assertStringContainsString('--omeka-admin-color-text: #faaaaa;', $body);

        $this->assertStringContainsString('--easyadmin-admin-color: #8b1a1a;', $body);
        $this->assertStringContainsString('--easyadmin-admin-color-5: #761616;', $body);
        $this->assertStringContainsString('--easyadmin-admin-color-10: #601212;', $body);
        $this->assertStringContainsString('--easyadmin-admin-color-15: #4b0e0e;', $body);
        $this->assertStringContainsString('--easyadmin-admin-color-text: #faaaaa;', $body);

        // The elements that the admin theme colors with the main color.
        $this->assertStringContainsString('#site-nav { background-color: var(--easyadmin-admin-color-5); }', $body);
        $this->assertStringContainsString('#user .logout { background-color: var(--easyadmin-admin-color-15); }', $body);
    }

    /**
     * The style is appended after the stylesheet of the core, else the rules of
     * the admin theme would win.
     */
    public function testStyleIsAfterTheStylesheetOfTheCore(): void
    {
        $this->settings()->set('easyadmin_admin_color', '#8b1a1a');

        $this->dispatch('/admin');
        $body = $this->getResponse()->getBody();

        $this->assertGreaterThan(
            strpos($body, 'application/asset/css/style.css'),
            strpos($body, '--easyadmin-admin-color')
        );
    }
}

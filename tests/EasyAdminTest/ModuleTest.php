<?php declare(strict_types=1);

namespace EasyAdminTest;

use EasyAdmin\Module;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Module.
 */
class ModuleTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->logout();
        parent::tearDown();
    }

    /**
     * The filter of the settings pages of the module is skipped when the core
     * has its own one, so the two are never displayed together.
     */
    public function testCoreSettingsFilterIsDetected(): void
    {
        $module = new \EasyAdmin\Module();
        $module->setServiceLocator($this->getApplication()->getServiceManager());

        $method = new \ReflectionMethod($module, 'hasCoreSettingsFilter');
        $method->setAccessible(true);
        $result = $method->invoke($module);

        $this->assertIsBool($result);
        // The features of the module are not skipped without the view helper
        // that renders the filter of the core.
        $hasHelper = $this->getService('ViewHelperManager')->has('settingsFilter');
        if (!$hasHelper) {
            $this->assertFalse($result);
        }
        // And they are skipped only with a core that has it.
        if ($result) {
            $this->assertTrue($hasHelper);
            $this->assertTrue(version_compare(\Omeka\Module::VERSION, '4.3', '>='));
        }
    }

    /**
     * The scripts of the settings pages must exist, else the pages break.
     */
    public function testSettingsFilterAssetsExist(): void
    {
        $dir = dirname(__DIR__, 2) . '/asset/js/';
        foreach ([
            'settings-fields.js',
            'settings-filter.js',
            'settings-filter-core.js',
            'settings-nav.js',
        ] as $file) {
            $this->assertFileExists($dir . $file);
        }
    }

    /**
     * Test module is installed.
     */
    public function testModuleIsInstalled(): void
    {
        $moduleManager = $this->getService('Omeka\ModuleManager');
        $module = $moduleManager->getModule('EasyAdmin');

        $this->assertNotNull($module);
        $this->assertEquals(
            \Omeka\Module\Manager::STATE_ACTIVE,
            $module->getState()
        );
    }

    /**
     * Test module class exists.
     */
    public function testModuleClassExists(): void
    {
        $this->assertTrue(class_exists(Module::class));
    }

    /**
     * Test module has getConfig method.
     */
    public function testModuleHasGetConfigMethod(): void
    {
        $module = new Module();
        $config = $module->getConfig();

        $this->assertIsArray($config);
    }

    /**
     * Test module config has required keys.
     */
    public function testModuleConfigHasRequiredKeys(): void
    {
        $module = new Module();
        $config = $module->getConfig();

        $this->assertArrayHasKey('controllers', $config);
        $this->assertArrayHasKey('router', $config);
        $this->assertArrayHasKey('navigation', $config);
        $this->assertArrayHasKey('form_elements', $config);
    }

    /**
     * Test controllers are registered.
     */
    public function testControllersAreRegistered(): void
    {
        $controllerManager = $this->getService('ControllerManager');

        $this->assertTrue(
            $controllerManager->has('EasyAdmin\Controller\Admin\CheckAndFix')
        );
        $this->assertTrue(
            $controllerManager->has('EasyAdmin\Controller\Admin\Cron')
        );
        $this->assertTrue(
            $controllerManager->has('EasyAdmin\Controller\Admin\Module')
        );
        $this->assertTrue(
            $controllerManager->has('EasyAdmin\Controller\Admin\Theme')
        );
        $this->assertTrue(
            $controllerManager->has('EasyAdmin\Controller\Admin\FileManager')
        );
        $this->assertTrue(
            $controllerManager->has('EasyAdmin\Controller\Admin\Backup')
        );
    }

    /**
     * Test forms are registered.
     */
    public function testFormsAreRegistered(): void
    {
        $formElementManager = $this->getService('FormElementManager');

        $this->assertTrue(
            $formElementManager->has(\EasyAdmin\Form\CheckAndFixForm::class)
        );
        $this->assertTrue(
            $formElementManager->has(\EasyAdmin\Form\SettingsFieldset::class)
        );
    }

    /**
     * Test navigation is registered.
     */
    public function testNavigationIsRegistered(): void
    {
        $navigation = $this->getService('Laminas\Navigation\EasyAdmin');

        $this->assertInstanceOf(
            \Laminas\Navigation\Navigation::class,
            $navigation
        );
    }

    /**
     * Test view helpers are registered.
     */
    public function testViewHelpersAreRegistered(): void
    {
        $viewHelperManager = $this->getService('ViewHelperManager');

        $this->assertTrue(
            $viewHelperManager->has('previousNext')
        );
        $this->assertTrue(
            $viewHelperManager->has('lastBrowsePage')
        );
    }

    /**
     * The enhancements of the settings pages are optional.
     */
    public function testSettingsEnhancementsAreDisabledByDefault(): void
    {
        $settings = $this->getService('Omeka\Settings');
        $settings->set('easyadmin_settings_enhancements', false);

        $this->dispatch('/admin/setting');

        $this->assertResponseStatusCode(200);
        $this->assertStringNotContainsString('settingsFilter', $this->getResponse()->getBody());
    }

    /**
     * Once enabled, the settings are classified and flagged, and a setting
     * holding an array must not break the comparison with its default.
     */
    public function testSettingsEnhancementsClassifyTheSettings(): void
    {
        $settings = $this->getService('Omeka\Settings');
        $settings->set('easyadmin_settings_enhancements', true);
        // A setting whose value is an array while its default is a scalar.
        $settings->set('browse_defaults_public_items', ['sort_by' => 'created']);

        // The settings are shared by the whole suite, so they are restored
        // even when an assertion fails.
        try {
            $this->dispatch('/admin/setting');
            $body = $this->getResponse()->getBody();

            $this->assertResponseStatusCode(200);
            $this->assertStringContainsString('settingsFilter', $body);
            $this->assertStringContainsString('"kinds"', $body);
            $this->assertStringContainsString('"status"', $body);
            // Casting an array to string would emit a warning in the output.
            $this->assertStringNotContainsString('Array to string conversion', $body);
        } finally {
            $settings->set('easyadmin_settings_enhancements', false);
            $settings->delete('browse_defaults_public_items');
        }
    }
}

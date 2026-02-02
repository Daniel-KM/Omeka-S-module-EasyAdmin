<?php declare(strict_types=1);

namespace EasyAdminTest\Delegator;

use CommonTest\AbstractHttpControllerTestCase;
use EasyAdmin\Delegator\AssetAdapterDelegator;
use Omeka\Api\Adapter\AssetAdapter;

/**
 * Tests for the AssetAdapterDelegator.
 */
class AssetAdapterDelegatorTest extends AbstractHttpControllerTestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test that the delegator is registered and returns correct instance.
     */
    public function testDelegatorIsRegistered(): void
    {
        $services = $this->getApplicationServiceLocator();
        $apiAdapterManager = $services->get('Omeka\ApiAdapterManager');

        $adapter = $apiAdapterManager->get('assets');

        $this->assertInstanceOf(
            AssetAdapterDelegator::class,
            $adapter,
            'Asset adapter should be delegated to AssetAdapterDelegator'
        );
    }

    /**
     * Test that delegator extends AssetAdapter for ACL compatibility.
     */
    public function testDelegatorExtendsAssetAdapter(): void
    {
        $services = $this->getApplicationServiceLocator();
        $apiAdapterManager = $services->get('Omeka\ApiAdapterManager');

        $adapter = $apiAdapterManager->get('assets');

        $this->assertInstanceOf(
            AssetAdapter::class,
            $adapter,
            'AssetAdapterDelegator should extend AssetAdapter'
        );
    }

    /**
     * Test that delegator returns correct resource name.
     */
    public function testDelegatorReturnsCorrectResourceName(): void
    {
        $services = $this->getApplicationServiceLocator();
        $apiAdapterManager = $services->get('Omeka\ApiAdapterManager');

        $adapter = $apiAdapterManager->get('assets');

        $this->assertSame('assets', $adapter->getResourceName());
    }

    /**
     * Test that default settings have empty arrays for additional media types.
     */
    public function testDefaultSettingsAreEmpty(): void
    {
        $services = $this->getApplicationServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $mediaTypes = $settings->get('easyadmin_asset_media_types', []);
        $extensions = $settings->get('easyadmin_asset_extensions', []);

        $this->assertIsArray($mediaTypes);
        $this->assertIsArray($extensions);
    }

    /**
     * Test that additional media types can be configured.
     */
    public function testAdditionalMediaTypesCanBeConfigured(): void
    {
        $services = $this->getApplicationServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // Set additional media types.
        $settings->set('easyadmin_asset_media_types', ['application/pdf', 'image/svg+xml']);
        $settings->set('easyadmin_asset_extensions', ['pdf', 'svg']);

        $mediaTypes = $settings->get('easyadmin_asset_media_types');
        $extensions = $settings->get('easyadmin_asset_extensions');

        $this->assertContains('application/pdf', $mediaTypes);
        $this->assertContains('image/svg+xml', $mediaTypes);
        $this->assertContains('pdf', $extensions);
        $this->assertContains('svg', $extensions);

        // Clean up.
        $settings->set('easyadmin_asset_media_types', []);
        $settings->set('easyadmin_asset_extensions', []);
    }
}

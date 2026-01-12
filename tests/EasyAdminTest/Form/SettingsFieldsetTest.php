<?php declare(strict_types=1);

namespace EasyAdminTest\Form;

use EasyAdmin\Form\SettingsFieldset;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Settings Fieldset.
 */
class SettingsFieldsetTest extends AbstractHttpControllerTestCase
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
     * Test fieldset can be instantiated.
     */
    public function testFieldsetCanBeInstantiated(): void
    {
        $formElementManager = $this->getService('FormElementManager');
        $fieldset = $formElementManager->get(SettingsFieldset::class);

        $this->assertInstanceOf(SettingsFieldset::class, $fieldset);
    }

    /**
     * Test fieldset has label.
     */
    public function testFieldsetHasLabel(): void
    {
        $formElementManager = $this->getService('FormElementManager');
        $fieldset = $formElementManager->get(SettingsFieldset::class);

        $this->assertNotEmpty($fieldset->getLabel());
    }
}

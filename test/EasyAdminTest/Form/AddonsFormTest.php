<?php declare(strict_types=1);

namespace EasyAdminTest\Form;

use EasyAdmin\Form\AddonsForm;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Addons Form.
 */
class AddonsFormTest extends AbstractHttpControllerTestCase
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
     * Test form can be instantiated.
     */
    public function testFormCanBeInstantiated(): void
    {
        $formElementManager = $this->getService('FormElementManager');
        $form = $formElementManager->get(AddonsForm::class);

        $this->assertInstanceOf(AddonsForm::class, $form);
    }

    /**
     * Test form has reset cache checkbox.
     */
    public function testFormHasResetCacheElement(): void
    {
        $formElementManager = $this->getService('FormElementManager');
        $form = $formElementManager->get(AddonsForm::class);

        $this->assertTrue($form->has('reset_cache'));
    }
}

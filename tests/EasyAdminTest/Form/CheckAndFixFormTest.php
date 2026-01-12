<?php declare(strict_types=1);

namespace EasyAdminTest\Form;

use EasyAdmin\Form\CheckAndFixForm;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin CheckAndFix Form.
 */
class CheckAndFixFormTest extends AbstractHttpControllerTestCase
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
        $form = $formElementManager->get(CheckAndFixForm::class);

        $this->assertInstanceOf(CheckAndFixForm::class, $form);
    }

    /**
     * Test form has files_checkfix fieldset.
     */
    public function testFormHasFilesCheckfixFieldset(): void
    {
        $formElementManager = $this->getService('FormElementManager');
        $form = $formElementManager->get(CheckAndFixForm::class);

        $this->assertTrue($form->has('files_checkfix'));
    }

    /**
     * Test form has database fieldset.
     */
    public function testFormHasDatabaseFieldset(): void
    {
        $formElementManager = $this->getService('FormElementManager');
        $form = $formElementManager->get(CheckAndFixForm::class);

        $this->assertTrue($form->has('database'));
    }
}

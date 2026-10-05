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
     * No element may have an empty label.
     *
     * The core of Omeka S 4.1 and below skips the row of a label that is null
     * and renders the label of an empty one, so an empty string breaks the
     * page with a DomainException from the helper formLabel.
     */
    public function testNoElementHasAnEmptyLabel(): void
    {
        $formElementManager = $this->getService('FormElementManager');
        $form = $formElementManager->get(CheckAndFixForm::class);

        $empty = [];
        $check = function ($fieldset, string $path) use (&$check, &$empty): void {
            foreach ($fieldset->getElements() as $element) {
                if ($element->getAttribute('type') === 'hidden') {
                    continue;
                }
                if ($element->getLabel() === '') {
                    $empty[] = $path . $element->getName();
                }
            }
            foreach ($fieldset->getFieldsets() as $sub) {
                $check($sub, $path . $sub->getName() . '/');
            }
        };
        $check($form, '');

        $this->assertSame([], $empty);
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

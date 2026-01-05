<?php declare(strict_types=1);

namespace EasyAdminTest\Job;

use EasyAdmin\Job\DbResourceTitle;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin DbResourceTitle Job.
 */
class DbResourceTitleTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test job class exists.
     */
    public function testJobClassExists(): void
    {
        $this->assertTrue(class_exists(DbResourceTitle::class));
    }

    /**
     * Test job extends AbstractCheck.
     */
    public function testJobExtendsAbstractCheck(): void
    {
        $reflection = new \ReflectionClass(DbResourceTitle::class);
        $this->assertTrue($reflection->isSubclassOf(\EasyAdmin\Job\AbstractCheck::class));
    }

    /**
     * Test job has perform method.
     */
    public function testJobHasPerformMethod(): void
    {
        $this->assertTrue(method_exists(DbResourceTitle::class, 'perform'));
    }

    /**
     * Test job has checkDbResourceTitleWithApi method.
     */
    public function testJobHasApiMethod(): void
    {
        $reflection = new \ReflectionClass(DbResourceTitle::class);
        $this->assertTrue($reflection->hasMethod('checkDbResourceTitleWithApi'));
    }

    /**
     * Test job has mapResourceTypeToName method.
     */
    public function testJobHasMapResourceTypeToNameMethod(): void
    {
        $reflection = new \ReflectionClass(DbResourceTitle::class);
        $this->assertTrue($reflection->hasMethod('mapResourceTypeToName'));

        $method = $reflection->getMethod('mapResourceTypeToName');
        $method->setAccessible(true);

        $job = $reflection->newInstanceWithoutConstructor();

        $this->assertEquals('items', $method->invoke($job, 'Omeka\Entity\Item'));
        $this->assertEquals('item_sets', $method->invoke($job, 'Omeka\Entity\ItemSet'));
        $this->assertEquals('media', $method->invoke($job, 'Omeka\Entity\Media'));
        $this->assertNull($method->invoke($job, 'Unknown\Entity'));
    }

    /**
     * Test API mode can be dispatched.
     */
    public function testApiModeCanBeDispatched(): void
    {
        // Create a test item directly (without dcterms:title that requires vocabulary).
        $response = $this->api()->create('items', []);
        $item = $response->getContent();
        $this->createdItems[] = $item->id();

        $this->assertNotEmpty($item->id());

        // Dispatch the job in check mode (doesn't modify data).
        $job = $this->dispatchJob(DbResourceTitle::class, [
            'process' => 'db_resource_title_check',
            'report_type' => 'partial',
        ]);

        $this->assertNotNull($job);
        $this->assertInstanceOf(\Omeka\Entity\Job::class, $job);
    }

    /**
     * Test direct mode parameters.
     */
    public function testDirectModeParameters(): void
    {
        // Create a test item directly.
        $response = $this->api()->create('items', []);
        $item = $response->getContent();
        $this->createdItems[] = $item->id();

        // Dispatch with direct mode (default).
        $job = $this->dispatchJob(DbResourceTitle::class, [
            'process' => 'db_resource_title_check',
            'report_type' => 'partial',
            'mode' => 'direct',
        ]);

        $this->assertNotNull($job);
    }

    /**
     * Test API mode parameters.
     */
    public function testApiModeParameters(): void
    {
        // Create a test item directly.
        $response = $this->api()->create('items', []);
        $item = $response->getContent();
        $this->createdItems[] = $item->id();

        // Dispatch with API mode.
        $job = $this->dispatchJob(DbResourceTitle::class, [
            'process' => 'db_resource_title_check',
            'report_type' => 'partial',
            'mode' => 'api',
        ]);

        $this->assertNotNull($job);
    }

    /**
     * Test form has mode option.
     */
    public function testFormHasModeOption(): void
    {
        $formClass = \EasyAdmin\Form\CheckAndFixForm::class;
        $this->assertTrue(class_exists($formClass));

        $form = $this->getService('FormElementManager')->get($formClass);
        $form->init();

        // Check that the db_resource_title fieldset exists.
        $resourceValues = $form->get('resource_values');
        $this->assertNotNull($resourceValues);

        $dbResourceTitle = $resourceValues->get('db_resource_title');
        $this->assertNotNull($dbResourceTitle);

        // Check mode field exists.
        $modeField = $dbResourceTitle->get('mode');
        $this->assertNotNull($modeField);

        // Check value options.
        $options = $modeField->getValueOptions();
        $this->assertArrayHasKey('direct', $options);
        $this->assertArrayHasKey('api', $options);
    }
}

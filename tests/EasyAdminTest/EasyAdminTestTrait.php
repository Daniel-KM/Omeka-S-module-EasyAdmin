<?php declare(strict_types=1);

namespace EasyAdminTest;

use Omeka\Entity\User;

/**
 * Trait with common helper methods for EasyAdmin tests.
 */
trait EasyAdminTestTrait
{
    /**
     * @var array IDs of created resources for cleanup.
     */
    protected $createdItems = [];
    protected $createdJobs = [];

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $serviceLocator = $this->getApplication()->getServiceManager();
        $auth = $serviceLocator->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $serviceLocator = $this->getApplication()->getServiceManager();
        $auth = $serviceLocator->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Get current authenticated user.
     */
    protected function getCurrentUser(): ?User
    {
        $serviceLocator = $this->getApplication()->getServiceManager();
        $auth = $serviceLocator->get('Omeka\AuthenticationService');
        return $auth->getIdentity();
    }

    /**
     * Get the API manager.
     */
    protected function api(): \Omeka\Api\Manager
    {
        return $this->getApplication()->getServiceManager()->get('Omeka\ApiManager');
    }

    /**
     * Get the entity manager.
     */
    protected function getEntityManager(): \Doctrine\ORM\EntityManager
    {
        return $this->getApplication()->getServiceManager()->get('Omeka\EntityManager');
    }

    /**
     * Get the settings service.
     */
    protected function settings(): \Omeka\Settings\Settings
    {
        return $this->getApplication()->getServiceManager()->get('Omeka\Settings');
    }

    /**
     * Get a service from the service locator.
     */
    protected function getService(string $name)
    {
        return $this->getApplication()->getServiceManager()->get($name);
    }

    /**
     * Create a test item.
     */
    protected function createItem(array $data = []): \Omeka\Api\Representation\ItemRepresentation
    {
        $defaultData = [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    '@value' => 'Test Item ' . uniqid(),
                ],
            ],
        ];
        $data = array_merge($defaultData, $data);

        $response = $this->api()->create('items', $data);
        $item = $response->getContent();
        $this->createdItems[] = $item->id();

        return $item;
    }

    /**
     * Clean up created resources.
     */
    protected function cleanupResources(): void
    {
        // Clean up items.
        foreach ($this->createdItems as $itemId) {
            try {
                $this->api()->delete('items', $itemId);
            } catch (\Exception $e) {
                // Ignore errors.
            }
        }
        $this->createdItems = [];
        $this->createdJobs = [];
    }

    /**
     * Dispatch a job and return the job entity.
     */
    protected function dispatchJob(string $class, array $args = []): \Omeka\Entity\Job
    {
        $dispatcher = $this->getService('Omeka\Job\Dispatcher');
        $job = $dispatcher->dispatch($class, $args);
        $this->createdJobs[] = $job->getId();
        return $job;
    }

    /**
     * Get the base path for files.
     */
    protected function getBasePath(): string
    {
        $config = $this->getService('Config');
        return $config['file_store']['local']['base_path'] ?? (OMEKA_PATH . '/files');
    }
}

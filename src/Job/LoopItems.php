<?php declare(strict_types=1);

namespace EasyAdmin\Job;

/**
 * Update all items, so all modules that use api events are triggered.
 *
 * This job can be use as a one-time task that helps to process existing items
 * when a new feature is added in a module.
 */
class LoopItems extends DbLoopResources
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $this->logger = $services->get('Omeka\Logger');
        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->entityManager = $services->get('Omeka\EntityManager');

        $this->processLoop('items');
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Omeka\Job\AbstractJob;

/**
 * Update all items, so all modules that use api events are triggered.
 *
 * This job can be use as a one-time task that help to process existing items
 * when a new feature is added in a module.
 */
class LoopItems extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const BULK_LIMIT = 100;

    public function perform(): void
    {
        /**
         * @var \Laminas\Log\Logger $logger
         * @var \Omeka\Api\Manager $api
         * @var \Doctrine\ORM\EntityManager $entityManager
         */
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');

        $resourceType = 'items';

        // Don't load entities if the only information needed is total results.
        $totalToProcess = $api->search($resourceType, ['limit' => 0])->getTotalResults();

        if (empty($totalToProcess)) {
            $logger->info(
                'No resource to process.' // @translate
            );
            return;
        }

        $logger->info(
            'Processing {count} resources.', // @translate
            ['count' => $totalToProcess]
        );

        $offset = 0;
        $totalProcessed = 0;
        while (true) {
            /** @var \Omeka\Api\Representation\AbstractRepresentation[] $resources */
            $resources = $api
                ->search($resourceType, [
                    'limit' => self::BULK_LIMIT,
                    'offset' => $offset,
                ])
                ->getContent();
            if (empty($resources)) {
                break;
            }

            foreach ($resources as $resource) {
                if ($this->shouldStop()) {
                    $logger->warn(
                        'The job "{name}" was stopped.', // @translate
                        ['name' => 'Loop items']
                    );
                    break 2;
                }

                // Update the resource without any change.
                $api->update($resourceType, $resource->id(), [], [], ['isPartial' => true]);

                ++$totalProcessed;

                // Avoid memory issue.
                unset($resource);
            }

            // Avoid memory issue.
            unset($resources);
            $entityManager->clear();

            $offset += self::BULK_LIMIT;
        }

        $logger->info(
            'End of the job: {count}/{total} processed.', // @translate
            ['count' => $totalProcessed, 'total' => $totalToProcess]
        );
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Omeka\Job\AbstractJob;

/**
 * Save specified resources, so all modules that use api events are triggered.
 *
 * This job can be use as a one-time task that helps to process existing
 * resources when a new setting is set, in particular for advanced resource
 * templates.
 */
class DbLoopResources extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const BULK_LIMIT = 100;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $this->logger = $services->get('Omeka\Logger');
        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->entityManager = $services->get('Omeka\EntityManager');

        $allowedResourceTypes = [
            'items',
            'item_sets',
            'media',
            'value_annotations',
            'annotations',
        ];
        $resourceTypes = $this->getArg('resource_types') ?: [];
        $resourceTypes = array_intersect($allowedResourceTypes, $resourceTypes);
        if (!count($resourceTypes)) {
            $this->logger->warn(
                'No resource types defined.' // @translate
            );
            return;
        }

        $this->logger->notice(
            'Resource types to process: {resource_types}.', // @translate
            ['resource_types' => implode(', ', $resourceTypes)]
        );

        $query = [];
        $queryArg = $this->getArg('query');
        if ($queryArg) {
            parse_str(ltrim((string) $queryArg, "? \t\n\r\0\x0B"), $query);
        }

        foreach ($resourceTypes as $resourceType) {
            $this->processLoop($resourceType, $query ?: []);
        }
    }

    protected function processLoop(string $resourceType, array $query): void
    {
        // Don't load entities if the only information needed is total results.
        // But keep limit if there is one.
        $totalToProcess = empty($query['limit'])
            ? $this->api->search($resourceType, ['limit' => 0] + $query)->getTotalResults()
            : $this->api->search($resourceType, $query, ['returnScalar' => 'id'])->getTotalResults();

        if (empty($totalToProcess)) {
            $this->logger->info(
                'No {resource_type} to process.', // @translate
                ['resource_type' => $this->easyMeta->resourceLabel($resourceType)]
            );
            return;
        }

        $this->logger->info(
            'Processing {count} {resource_type}.', // @translate
            ['count' => $totalToProcess, 'resource_type' => $this->easyMeta->resourceLabelPlural($resourceType)]
        );

        $offset = 0;
        $totalProcessed = 0;
        while (true) {
            $resourceIds = $this->api->search(
                $resourceType,
                [
                    'limit' => self::BULK_LIMIT,
                    'offset' => $offset,
                ],
                ['returnScalar' => 'id'])
                ->getContent();
            if (empty($resourceIds)) {
                break;
            }

            foreach ($resourceIds as $resourceId) {
                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job "{name}" was stopped.', // @translate
                        ['name' => 'Loop resources']
                    );
                    break 2;
                }

                // Update the resource without any change here, but eventually
                // in triggers.
                $this->api->update($resourceType, $resourceId, [], [], ['isPartial' => true]);

                ++$totalProcessed;
            }

            $this->logger->info(
                '{processed}/{total} resources processed.', // @translate
                ['processed' => $totalProcessed, 'total' => $totalToProcess]
            );

            // Avoid memory issue.
            unset($resourceIds);
            $this->entityManager->clear();

            $offset += self::BULK_LIMIT;
        }

        $this->logger->notice(
            'End of process: {count}/{total} {resource_type} processed.', // @translate
            ['count' => $totalProcessed, 'total' => $totalToProcess, 'resource_type' => $this->easyMeta->resourceLabelPlural($resourceType)]
        );
    }
}

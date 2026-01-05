<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Omeka\Entity\Resource;

class DbResourceTitle extends AbstractCheck
{
    protected $columns = [
        'type' => 'Type', // @translate
        'resource' => 'Resource', // @translate
        'existing' => 'Existing title', // @translate
        'real' => 'Real title', // @translate
        'different' => 'Different', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $repository;

    /**
     * @var bool
     */
    protected $reportFull = false;

    /**
     * @var array
     */
    protected $templateTitleTerms = [];

    /**
     * Fallback property ids per template (from AdvancedResourceTemplate).
     * @var array
     */
    protected $templateFallbackTerms = [];

    /**
     * @var bool
     */
    protected $hasAdvancedResourceTemplate = false;

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_resource_title_fix';

        // Report type: 'full' lists all resources, 'partial' lists only different.
        $this->reportFull = $this->getArg('report_type') === 'full';

        $this->checkDbResourceTitle($processFix);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();

        if ($processFix) {
            $this->logger->warn(
                'You should reindex the Omeka metadata.' // @translate
            );
        }
    }

    /**
     * Check the title according to the resource template and the title value.
     */
    protected function checkDbResourceTitle(bool $fix): bool
    {
        $sql = 'SELECT COUNT(id) FROM resource;';
        $totalResources = $this->connection->executeQuery($sql)->fetchOne();
        if (empty($totalResources)) {
            $this->logger->notice(
                'No resource to process.' // @translate
            );
            return true;
        }

        $totalToProcess = (int) $totalResources;

        // For quick process, get all the title terms of all templates one time.
        $sql = <<<'SQL'
            SELECT id, IFNULL(title_property_id, 1) AS "title_property_id"
            FROM resource_template
            ORDER BY id ASC;
            SQL;
        $this->templateTitleTerms = $this->connection->executeQuery($sql)->fetchAllKeyValue();

        // Check for AdvancedResourceTemplate fallback properties.
        $this->prepareAdvancedResourceTemplateFallbacks();

        // Use SQL for check mode (faster), entities for fix mode (need persist).
        if ($fix) {
            return $this->checkDbResourceTitleWithEntities($totalToProcess);
        }

        return $this->checkDbResourceTitleWithSql($totalToProcess);
    }

    /**
     * Prepare fallback properties from AdvancedResourceTemplate if available.
     */
    protected function prepareAdvancedResourceTemplateFallbacks(): void
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('AdvancedResourceTemplate');
        $this->hasAdvancedResourceTemplate = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        if (!$this->hasAdvancedResourceTemplate) {
            return;
        }

        // Get fallback properties from resource_template_data table.
        $sql = <<<'SQL'
            SELECT resource_template_id, data
            FROM resource_template_data
            SQL;
        $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();

        $easyMeta = $services->get('Common\EasyMeta');

        foreach ($rows as $row) {
            $templateId = (int) $row['resource_template_id'];
            $data = json_decode($row['data'], true);
            if (!$data || empty($data['title_fallback_properties'])) {
                continue;
            }
            // Convert property terms to ids.
            $fallbackIds = [];
            foreach ($data['title_fallback_properties'] as $term) {
                $propId = $easyMeta->propertyId($term);
                if ($propId) {
                    $fallbackIds[] = $propId;
                }
            }
            if ($fallbackIds) {
                $this->templateFallbackTerms[$templateId] = $fallbackIds;
            }
        }

        if (count($this->templateFallbackTerms)) {
            $this->logger->info(
                '{count} templates have fallback title properties.', // @translate
                ['count' => count($this->templateFallbackTerms)]
            );
        }
    }

    /**
     * Check titles using raw SQL (faster, for check-only mode).
     */
    protected function checkDbResourceTitleWithSql(int $totalToProcess): bool
    {
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate

        // Use larger batch for scalar queries.
        $batchSize = self::SQL_LIMIT_LARGE;

        // SQL to get resources with their current title and template.
        $sqlBase = <<<'SQL'
            SELECT r.id, r.resource_type, r.title, r.resource_template_id
            FROM resource r
            ORDER BY r.id ASC
            SQL;

        // SQL to get the first value for a property (title) per resource.
        // This handles literal values, URIs, but not linked resources recursively.
        $sqlValues = <<<'SQL'
            SELECT v.resource_id,
                   COALESCE(v.value, v.uri) AS computed_title
            FROM value v
            WHERE v.resource_id IN (:ids)
              AND v.property_id = :prop
            ORDER BY v.resource_id, v.id
            SQL;

        $offset = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;

        while (true) {
            $sql = $sqlBase . ' LIMIT ' . (int) $batchSize . ' OFFSET ' . (int) $offset;
            $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();
            if (!count($rows) || $totalProcessed >= $totalToProcess) {
                break;
            }

            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return false;
            }

            if ($totalProcessed) {
                $this->logger->info(
                    '{processed}/{total} resources processed.', // @translate
                    ['processed' => $totalProcessed, 'total' => $totalToProcess]
                );
            }

            // Group resources by title property to batch value queries.
            $resourcesByTitleProp = [];
            $resourceData = [];
            foreach ($rows as $row) {
                $resourceId = (int) $row['id'];
                $templateId = $row['resource_template_id'];
                $titlePropId = ($templateId && isset($this->templateTitleTerms[$templateId]))
                    ? (int) $this->templateTitleTerms[$templateId]
                    : 1;
                $resourcesByTitleProp[$titlePropId][] = $resourceId;
                $resourceData[$resourceId] = [
                    'type' => $row['resource_type'],
                    'title' => $row['title'],
                    'title_prop_id' => $titlePropId,
                    'template_id' => $templateId,
                ];
            }

            // Fetch computed titles for each title property group.
            $computedTitles = [];
            foreach ($resourcesByTitleProp as $titlePropId => $resourceIds) {
                $result = $this->connection->executeQuery(
                    $sqlValues,
                    ['ids' => $resourceIds, 'prop' => $titlePropId],
                    ['ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY, 'prop' => \PDO::PARAM_INT]
                )->fetchAllAssociative();
                // Keep only first value per resource.
                foreach ($result as $valueRow) {
                    $resId = (int) $valueRow['resource_id'];
                    if (!isset($computedTitles[$resId])) {
                        $computedTitles[$resId] = $valueRow['computed_title'];
                    }
                }
            }

            // Handle fallback properties from AdvancedResourceTemplate.
            if ($this->hasAdvancedResourceTemplate) {
                $this->applyFallbackTitles($resourceData, $computedTitles, $sqlValues);
            }

            // Compare titles.
            foreach ($resourceData as $resourceId => $data) {
                $existingTitle = $data['title'];
                $realTitle = $computedTitles[$resourceId] ?? null;

                // Normalize titles like Omeka does.
                if ($existingTitle === '' || $existingTitle === null) {
                    $existingTitle = null;
                    $shortExistingTitle = '';
                } else {
                    $shortExistingTitle = strtr(mb_substr($existingTitle, 0, 1000), ["\n" => ' ', "\r" => ' ', "\v" => ' ', "\t" => ' ']);
                }

                if ($realTitle === null || $realTitle === '' || trim($realTitle) === '') {
                    $realTitle = null;
                    $shortRealTitle = '';
                } else {
                    $realTitle = trim($realTitle);
                    $shortRealTitle = strtr(mb_substr($realTitle, 0, 1000), ["\n" => ' ', "\r" => ' ', "\v" => ' ', "\t" => ' ']);
                }

                $different = $existingTitle !== $realTitle;

                if ($different) {
                    ++$totalSucceed;
                    $row = [
                        'type' => $data['type'],
                        'resource' => $resourceId,
                        'existing' => $shortExistingTitle,
                        'real' => $shortRealTitle,
                        'different' => $yes,
                        'fixed' => '',
                    ];
                    $this->writeRow($row);
                } elseif ($this->reportFull) {
                    $row = [
                        'type' => $data['type'],
                        'resource' => $resourceId,
                        'existing' => $shortExistingTitle,
                        'real' => $shortRealTitle,
                        'different' => '',
                        'fixed' => '',
                    ];
                    $this->writeRow($row);
                }

                ++$totalProcessed;
            }

            unset($rows, $resourcesByTitleProp, $resourceData, $computedTitles);
            $offset += $batchSize;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed, {total_succeed} different.', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
                'total_succeed' => $totalSucceed,
            ]
        );

        return true;
    }

    /**
     * Check and fix titles using Doctrine entities (for fix mode).
     */
    protected function checkDbResourceTitleWithEntities(int $totalToProcess): bool
    {
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate

        $offset = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;

        while (true) {
            /** @var \Omeka\Entity\Resource[] $resources */
            $resources = $this->resourceRepository->findBy([], ['id' => 'ASC'], self::SQL_LIMIT, $offset);
            if (!count($resources)) {
                break;
            }

            if ($offset) {
                $this->logger->info(
                    '{processed}/{total} resources processed.', // @translate
                    ['processed' => $offset, 'total' => $totalToProcess]
                );

                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job was stopped.' // @translate
                    );
                    return false;
                }
            }

            foreach ($resources as $resource) {
                $template = $resource->getResourceTemplate();
                $titleTermId = $template && isset($this->templateTitleTerms[$template->getId()])
                    ? (int) $this->templateTitleTerms[$template->getId()]
                    : 1;

                $existingTitle = $resource->getTitle();
                $realTitle = $this->getValueFromResource($resource, $titleTermId);

                // Try fallback properties if title is empty and AdvancedResourceTemplate is active.
                if (($realTitle === null || $realTitle === '')
                    && $this->hasAdvancedResourceTemplate
                    && $template
                    && isset($this->templateFallbackTerms[$template->getId()])
                ) {
                    foreach ($this->templateFallbackTerms[$template->getId()] as $fallbackPropId) {
                        $fallbackTitle = $this->getValueFromResource($resource, $fallbackPropId);
                        if ($fallbackTitle !== null && $fallbackTitle !== '') {
                            $realTitle = $fallbackTitle;
                            break;
                        }
                    }
                }

                if ($existingTitle === '' || $existingTitle === null) {
                    $existingTitle = null;
                    $shortExistingTitle = '';
                } else {
                    $shortExistingTitle = strtr(mb_substr($existingTitle, 0, 1000), ["\n" => ' ', "\r" => ' ', "\v" => ' ', "\t" => ' ']);
                }

                if ($realTitle === null || $realTitle === '' || trim($realTitle) === '') {
                    $realTitle = null;
                    $shortRealTitle = '';
                } else {
                    $realTitle = trim($realTitle);
                    $shortRealTitle = strtr(mb_substr($realTitle, 0, 1000), ["\n" => ' ', "\r" => ' ', "\v" => ' ', "\t" => ' ']);
                }

                $different = $existingTitle !== $realTitle;

                if ($different) {
                    ++$totalSucceed;
                    $resource->setTitle($realTitle);
                    $this->entityManager->persist($resource);
                    $row = [
                        'type' => $resource->getResourceName(),
                        'resource' => $resource->getId(),
                        'existing' => $shortExistingTitle,
                        'real' => $shortRealTitle,
                        'different' => $yes,
                        'fixed' => $yes,
                    ];
                    $this->writeRow($row);
                } elseif ($this->reportFull) {
                    $row = [
                        'type' => $resource->getResourceName(),
                        'resource' => $resource->getId(),
                        'existing' => $shortExistingTitle,
                        'real' => $shortRealTitle,
                        'different' => '',
                        'fixed' => '',
                    ];
                    $this->writeRow($row);
                }

                // Avoid memory issue.
                unset($resource);

                ++$totalProcessed;
            }

            unset($resources);
            $this->entityManager->flush();
            $this->entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed, {total_succeed} updated.', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
                'total_succeed' => $totalSucceed,
            ]
        );

        return true;
    }

    /**
     * Recursively get the first value of a resource for a term.
     */
    protected function getValueFromResource(Resource $resource, int $termId, int $loop = 0): ?string
    {
        if ($loop > 20) {
            return null;
        }

        /** @var \Omeka\Entity\Value[] $values */
        $values = $resource->getValues()->toArray();
        $values = array_filter($values, fn (\Omeka\Entity\Value $v) => $v->getProperty()->getId() === $termId);
        if (!count($values)) {
            return null;
        }

        /** @var \Omeka\Entity\Value $value */
        $value = reset($values);
        unset($values);
        $val = (string) $value->getValue();
        if ($val !== '') {
            return $val;
        }

        if ($val = $value->getUri()) {
            return $val;
        }

        $valueResource = $value->getValueResource();
        if (!$valueResource) {
            return null;
        }

        return $this->getValueFromResource($valueResource, $termId, ++$loop);
    }

    /**
     * Apply fallback title properties from AdvancedResourceTemplate.
     *
     * For resources with empty computed titles, check fallback properties in order.
     */
    protected function applyFallbackTitles(array $resourceData, array &$computedTitles, string $sqlValues): void
    {
        // Find resources with empty titles that have fallback properties configured.
        $resourcesNeedingFallback = [];
        foreach ($resourceData as $resourceId => $data) {
            $templateId = $data['template_id'];
            if ($templateId
                && isset($this->templateFallbackTerms[$templateId])
                && (!isset($computedTitles[$resourceId]) || $computedTitles[$resourceId] === null || $computedTitles[$resourceId] === '')
            ) {
                $resourcesNeedingFallback[$resourceId] = $templateId;
            }
        }

        if (!count($resourcesNeedingFallback)) {
            return;
        }

        // Group resources by template to batch queries.
        $byTemplate = [];
        foreach ($resourcesNeedingFallback as $resourceId => $templateId) {
            $byTemplate[$templateId][] = $resourceId;
        }

        // For each template, try fallback properties in order.
        foreach ($byTemplate as $templateId => $resourceIds) {
            $fallbackProps = $this->templateFallbackTerms[$templateId];

            // Try each fallback property in order.
            foreach ($fallbackProps as $propId) {
                // Find resources still needing a title.
                $stillNeeding = array_filter($resourceIds, fn ($id) => !isset($computedTitles[$id]) || $computedTitles[$id] === null || $computedTitles[$id] === '');
                if (!count($stillNeeding)) {
                    break;
                }

                // Query values for this fallback property.
                $result = $this->connection->executeQuery(
                    $sqlValues,
                    ['ids' => array_values($stillNeeding), 'prop' => $propId],
                    ['ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY, 'prop' => \PDO::PARAM_INT]
                )->fetchAllAssociative();

                // Apply first non-empty value per resource.
                foreach ($result as $valueRow) {
                    $resId = (int) $valueRow['resource_id'];
                    $val = $valueRow['computed_title'];
                    if ($val !== null && $val !== '' && (!isset($computedTitles[$resId]) || $computedTitles[$resId] === null || $computedTitles[$resId] === '')) {
                        $computedTitles[$resId] = $val;
                    }
                }
            }
        }
    }
}

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

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_resource_title_fix';

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

        $totalToProcess = $totalResources;

        // For quick process, get all the title terms of all templates one time.
        $sql = <<<'SQL'
SELECT id, IFNULL(title_property_id, 1) AS "title_property_id"
FROM resource_template
ORDER BY id ASC;
SQL;
        $templateTitleTerms = $this->connection->executeQuery($sql)->fetchAllKeyValue();

        // It's possible to do the process with some not so complex sql queries,
        // but it's done manually for now. May be complicate with the title of
        // the linked resources.

        // Do the process.

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
                $this->logger->notice(
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
                $titleTermId = $template && isset($templateTitleTerms[$template->getId()])
                    ? (int) $templateTitleTerms[$template->getId()]
                    : 1;

                $existingTitle = $resource->getTitle();
                $realTitle = $this->getValueFromResource($resource, $titleTermId);

                if ($existingTitle === '' || $existingTitle === null) {
                    $existingTitle = null;
                    $shortExistingTitle = '';
                } else {
                    $shortExistingTitle = str_replace(["\n", "\r", "\v", "\t"], [' ', ' ', ' ', ' '], mb_substr($existingTitle, 0, 1000));
                }

                // Real title is trimmed too, like in Omeka.
                if ($realTitle === null || $realTitle === '' || trim($realTitle) === '') {
                    $realTitle = null;
                    $shortRealTitle = '';
                } else {
                    $realTitle = trim($realTitle);
                    $shortRealTitle = str_replace(["\n", "\r", "\v", "\t"], [' ', ' ', ' ', ' '], mb_substr($realTitle, 0, 1000));
                }

                $different = $existingTitle !== $realTitle;

                $row = [
                    'type' => $resource->getResourceName(),
                    'resource' => $resource->getId(),
                    'existing' => $shortExistingTitle,
                    'real' => $shortRealTitle,
                    'different' => '',
                    'fixed' => '',
                ];

                if ($different) {
                    ++$totalSucceed;
                    $row['different'] = $yes;
                    if ($fix) {
                        $resource->setTitle($realTitle);
                        $this->entityManager->persist($resource);
                        $this->logger->info(
                            'Fixed title for resource "{resource_type}" #{resource_id}.', // @translate
                            ['resource_id' => $resource->getId(), 'resource_type' => $resource->getResourceName(), 'resource_id' => $resource->getId()]
                        );
                        $row['fixed'] = $yes;
                    } else {
                        if ($realTitle === null) {
                            $this->logger->info(
                                'Title for resource "{resource_type}" #{resource_id} should be empty.', // @translate
                                ['resource_type' => $resource->getResourceName(), 'resource_id' => $resource->getId()]
                            );
                        } else {
                            $this->logger->info(
                                'Title for resource "{resource_type}" #{resource_id} should be "{title}".', // @translate
                                ['resource_type' => $resource->getResourceName(), 'resource_id' => $resource->getId(), 'title' => $shortRealTitle]
                            );
                        }
                    }
                }

                $this->writeRow($row);

                // Avoid memory issue.
                unset($resource);

                ++$totalProcessed;
            }

            unset($resources);
            $this->entityManager->flush();
            $this->entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        if ($fix) {
            $this->logger->notice(
                'End of process: {processed}/{total} processed, {total_succeed} updated.', // @translate
                [
                    'processed' => $totalProcessed,
                    'total' => $totalToProcess,
                    'total_succeed' => $totalSucceed,
                ]
                );
        } else {
            $this->logger->notice(
                'End of process: {processed}/{total} processed, {total_succeed} different.', // @translate
                [
                    'processed' => $totalProcessed,
                    'total' => $totalToProcess,
                    'total_succeed' => $totalSucceed,
                ]
            );
        }

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
        $values = array_filter($values, function (\Omeka\Entity\Value $v) use ($termId) {
            return $v->getProperty()->getId() === $termId;
        });
        if (!count($values)) {
            return null;
        }

        /** @var \Omeka\Entity\Value $value */
        $value = reset($values);
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
}

<?php declare(strict_types=1);

namespace BulkCheck\Job;

class DbUtf8Encode extends AbstractCheck
{
    protected $columns = [
        'type' => 'Type', // @translate
        'resource' => 'Resource', // @translate
        'value' => 'Value id', // @translate
        'term' => 'Term', // @translate
        'content' => 'Content', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $repository;

    /**
     * @var array
     */
    protected $properties;

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_utf8_encode_fix';

        $typeResources = $this->getArg('type_resources', []);
        $typeResources = array_intersect($typeResources, ['value', 'resource_title', 'page_block', 'page_title']);
        if (!count($typeResources)) {
            $this->logger->warn(
                'You should specify the types of records to check or fix.' // @translate
            );
            return;
        }

        $this->getProperties();

        foreach ($typeResources as $typeResource) {
            $this->checkDbUtf8Encode($processFix, $typeResource);
        }

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
     * Check the content encoding for resource values and titles.
     *
     * @param bool $fix
     * @param string $recordData
     * @return bool
     */
    protected function checkDbUtf8Encode(bool $fix, string $recordData): bool
    {
        switch ($recordData) {
            case 'value':
                $type = 'Value';
                $this->repository = $this->entityManager->getRepository(\Omeka\Entity\Value::class);
                $table = 'value';
                $column = 'value';
                $methodGet = 'getValue';
                $methodSet = 'setValue';
                break;
            case 'resource_title':
                $type = 'Resource title';
                $this->repository = $this->entityManager->getRepository(\Omeka\Entity\Resource::class);
                $table = 'resource';
                $column = 'title';
                $methodGet = 'getTitle';
                $methodSet = 'setTitle';
                break;
            case 'page_block':
                $type = 'Page block';
                $this->repository = $this->entityManager->getRepository(\Omeka\Entity\SitePageBlock::class);
                $table = 'site_page_block';
                $column = 'data';
                $methodGet = 'getData';
                $methodSet = 'setData';
                break;
            case 'page_title':
                $type = 'Page title';
                $this->repository = $this->entityManager->getRepository(\Omeka\Entity\SitePage::class);
                $table = 'site_page';
                $column = 'title';
                $methodGet = 'getTitle';
                $methodSet = 'setTitle';
                break;
            default:
                return false;
        }

        $baseCriteria = new \Doctrine\Common\Collections\Criteria();
        $expr = $baseCriteria->expr();
        $baseCriteria
            ->where($expr->neq($column, null))
            ->andWhere($expr->neq($column, ''))
            ->orderBy(['id' => 'ASC'])
            ->setFirstResult(null)
            ->setMaxResults(self::SQL_LIMIT);

        $sql = "SELECT COUNT(id) FROM $table WHERE $column IS NOT NULL and $column != '';";
        $totalToProcess = $this->connection->query($sql)->fetchColumn();
        $this->logger->notice(
            'Checking {total} records "{name}" with a "{value}".', // @translate
            ['total' => $totalToProcess, 'name' => $table, 'value' => $column]
        );

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate

        // Loop all media with original files.
        $offset = 0;
        $totalProcessed = 0;
        $totalUtf8 = 0;
        $totalSucceed = 0;
        $maxRows = self::SPREADSHEET_ROW_LIMIT;
        while (true) {
            $criteria = clone $baseCriteria;
            $criteria->setFirstResult($offset);
            $entities = $this->repository->matching($criteria);
            if (!$entities->count() || $offset >= $entities->count()) {
                break;
            }

            if ($offset) {
                $this->logger->info(
                    '{processed}/{total} records processed.', // @translate
                    ['processed' => $offset, 'total' => $totalToProcess]
                );

                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job was stopped.' // @translate
                    );
                    return false;
                }
            }

            /** @var \Omeka\Entity\AbstractEntity $entity */
            foreach ($entities as $entity) {
                ++$totalProcessed;

                $string = $entity->$methodGet();
                if ($recordData === 'page_block') {
                    $string = json_encode($string, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
                }
                // Same, but may be useful for a more complex check.
                // $iso = mb_convert_encoding($string, 'UTF-8', 'ISO-8859-15');
                $iso = utf8_decode($string);
                if ($string === $iso) {
                    // Don't log well formatted values because they are many.
                    ++$totalUtf8;
                    continue;
                }

                // Quick check for ascii encoding.
                $stringEncoding = mb_detect_encoding($string);
                $isoEncoding = mb_detect_encoding($iso);
                if (!$stringEncoding === 'ASCII' || $isoEncoding === 'ASCII') {
                    ++$totalUtf8;
                    continue;
                }

                // Quick check with the length.
                if (strlen($iso) === mb_strlen($iso) && strlen($iso) === mb_strlen($string)) {
                    ++$totalUtf8;
                    continue;
                }

                // If the original value and the converted value are valid utf-8
                // together, there is an issue!
                $stringIsUtf8 = preg_match('!!u', $string);
                $isoIsUtf8 = preg_match('!!u', $iso);
                if (!($stringIsUtf8 && $isoIsUtf8)) {
                    ++$totalUtf8;
                    continue;
                }

                switch ($recordData) {
                    case 'value':
                        $row = [
                            'type' => $type,
                            'resource' => $entity->getResource()->getId(),
                            'value' => $entity->getId(),
                            'term' => $this->properties[$entity->getProperty()->getId()],
                        ];
                        break;
                    case 'resource_title':
                        $row = [
                            'type' => $type,
                            'resource' => $entity->getId(),
                            'value' => '',
                            'term' => 'title',
                        ];
                        break;
                    case 'page_block':
                        $row = [
                            'type' => $type,
                            'resource' => $entity->getPage()->getId(),
                            'value' => $entity->getId(),
                            'term' => $entity->getLayout(),
                        ];
                        break;
                    case 'page_title':
                        $row = [
                            'type' => $type,
                            'resource' => $entity->getId(),
                            'value' => '',
                            'term' => 'title',
                        ];
                        break;
                    default:
                        return false;
                }
                $row['content'] = mb_substr(trim(str_replace(["\n", "\r", "\t"], ['  ', '  ', '  '], $string)), 0, 1000);
                $row['fixed'] = $fix ? $yes : '';

                if ($fix) {
                    if ($recordData === 'page_block') {
                        $iso = json_decode($iso, true);
                    }
                    // The iso value will be a utf8 value in the database.
                    $entity->$methodSet($iso);
                    $this->entityManager->persist($entity);
                    ++$totalSucceed;
                }

                if (--$maxRows >= 0) {
                    $this->writeRow($row);
                }
            }

            $this->entityManager->flush();
            $this->repository->clear();
            unset($entities);

            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed, {total_utf8} already utf-8, {total_succeed} converted ({type}).', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
                'total_utf8' => $totalUtf8,
                'total_succeed' => $totalSucceed,
                'type' => $type,
            ]
        );

        return true;
    }

    /**
     * Get all property terms by id.
     *
     * @return array Associative array of terms by id.
     */
    public function getProperties(): array
    {
        if (isset($this->properties)) {
            return $this->properties;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select([
                'DISTINCT property.id AS id',
                'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id',
                'property.id',
            ])
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        $stmt = $this->connection->executeQuery($qb);
        // Fetch by key pair is not supported by doctrine 2.0.
        $this->properties = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->properties = array_column($this->properties, 'term', 'id');
        return $this->properties;
    }
}

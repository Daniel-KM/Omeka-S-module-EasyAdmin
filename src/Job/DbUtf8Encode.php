<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbUtf8Encode extends AbstractCheck
{
    protected $totalUtf8 = 0;

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

        $typeResources = $this->getArg('type_resources', []) ?: [];
        $availables = [
            'resource_title',
            'value',
            'site_title',
            'site_summary',
            'page_title',
            'page_block',
        ];
        if (in_array('all', $typeResources)) {
            $typeResources = $availables;
        } else {
            $typeResources = array_intersect($typeResources, $availables);
            if (!count($typeResources)) {
                $this->logger->warn(
                    'You should specify the types of records to check or fix.' // @translate
                );
                return;
            }
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
            case 'site_title':
                $type = 'Site title';
                $this->repository = $this->entityManager->getRepository(\Omeka\Entity\Site::class);
                $table = 'site';
                $column = 'title';
                $methodGet = 'getTitle';
                $methodSet = 'setTitle';
                break;
            case 'site_summary':
                $type = 'Site summary';
                $this->repository = $this->entityManager->getRepository(\Omeka\Entity\Site::class);
                $table = 'site';
                $column = 'summary';
                $methodGet = 'getSummary';
                $methodSet = 'setSummary';
                break;
            case 'page_title':
                $type = 'Page title';
                $this->repository = $this->entityManager->getRepository(\Omeka\Entity\SitePage::class);
                $table = 'site_page';
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
        $totalToProcess = $this->connection->executeQuery($sql)->fetchOne();
        $this->logger->notice(
            'Checking {total} records "{name}" with a "{value}".', // @translate
            ['total' => $totalToProcess, 'name' => $table, 'value' => $column]
        );

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate

        // Loop all media with original files.
        $offset = 0;
        $totalProcessed = 0;
        $this->totalUtf8 = 0;
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
                if (!is_string($string) && !is_array($string)) {
                    continue;
                }

                if ((is_string($string) && !strlen($string))
                    || (is_array($string) && !count($string))
                ) {
                    continue;
                }

                $isPageBlock = $recordData === 'page_block';
                $iso = $this->convertToUnicode($string, $isPageBlock);
                if (is_null($iso)) {
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
                    case 'site_title':
                    case 'site_summary':
                        $row = [
                            'type' => $type,
                            'resource' => $entity->getId() . ' [' . $entity->getSlug() . ']',
                            'value' => $entity->getId(),
                            'term' => $column,
                        ];
                        break;
                    case 'page_title':
                        $row = [
                            'type' => $type,
                            'resource' => $entity->getId(),
                            'value' => $entity->getSlug(),
                            'term' => 'title',
                        ];
                        break;
                    case 'page_block':
                        $row = [
                            'type' => $type,
                            'resource' => $entity->getPage()->getId() . ' [' . $entity->getPage()->getSlug() . ']',
                            'value' => $entity->getId(),
                            'term' => $entity->getLayout(),
                        ];
                        break;
                    default:
                        return false;
                }
                $row['content'] = mb_substr(trim(str_replace(["\n", "\r", "\t"], ['  ', '  ', '  '], is_string($string) ? $string : json_encode($string))), 0, 1000);
                $row['fixed'] = $fix ? $yes : '';

                if ($fix) {
                    if ($isPageBlock) {
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
            $this->entityManager->clear();
            unset($entities);

            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed, {total_utf8} already utf-8, {total_succeed} converted ({type}).', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
                'total_utf8' => $this->totalUtf8,
                'total_succeed' => $totalSucceed,
                'type' => $type,
            ]
        );

        return true;
    }

    protected function convertToUnicode($string, $isPageBlock = false): ?string
    {
        if ($isPageBlock) {
            $hasHtml = !empty($string['html']);
            if ($hasHtml) {
                // Don't convert main xml entities (quotes, > and <).
                $string['html'] = str_replace(['&_gt_;', '&_lt_;'], ['&gt;', '&lt;'], html_entity_decode(
                    str_replace(['&gt;', '&lt;'], ['&_gt_;', '&_lt_;'], $string['html']),
                    ENT_NOQUOTES | ENT_HTML5,
                    'ISO-8859-15'
                ));
            }
            // JSON_INVALID_UTF8_IGNORE is only in php 7.2.
            $stringTest = json_encode($string, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
            $string = $stringTest === false
                ? json_encode($string, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS)
                : $stringTest;
            $iso = $this->convertToUnicode($string);
            return is_null($iso)
                ? json_encode($string, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS)
                : (is_array($iso) ? json_encode($iso, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS) : $iso);
        }

        // Same, but may be useful for a more complex check.
        // $iso = mb_convert_encoding($string, 'UTF-8', 'ISO-8859-15');
        $iso = utf8_decode($string);
        if ($string === $iso) {
            // Don't log well formatted values because they are many.
            ++$this->totalUtf8;
            return null;
        }

        // Quick check for ascii encoding.
        $stringEncoding = mb_detect_encoding($string);
        $isoEncoding = mb_detect_encoding($iso);
        if (!$stringEncoding === 'ASCII' || $isoEncoding === 'ASCII') {
            ++$this->totalUtf8;
            return null;
        }

        // Quick check with the length.
        if (strlen($iso) === mb_strlen($iso) && strlen($iso) === mb_strlen($string)) {
            ++$this->totalUtf8;
            return null;
        }

        // If the original value and the converted value are valid utf-8
        // together, there is an issue!
        $stringIsUtf8 = preg_match('!!u', $string);
        $isoIsUtf8 = preg_match('!!u', $iso);
        if (!($stringIsUtf8 && $isoIsUtf8)) {
            ++$this->totalUtf8;
            return null;
        }

        return $iso;
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
            ->select(
                'property.id AS id',
                'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id',
            )
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        $this->properties = $this->connection->executeQuery($qb)->fetchAllKeyValue();
        return $this->properties;
    }
}

<?php declare(strict_types=1);

namespace BulkCheck\Job;

class DbUtf8Encode extends AbstractCheck
{
    protected $columns = [
        'resource' => 'Resource', // @translate
        'value' => 'Value', // @translate
        'term' => 'Term', // @translate
        'content' => 'Content', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $valueRepository;

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
        $processFix = $process === 'db_utf8encode_fix';

        $this->getProperties();

        $this->checkDbUtf8Encode($processFix);

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
     * Check the content encoding for values.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkDbUtf8Encode($fix = false): bool
    {
        $this->valueRepository = $this->entityManager->getRepository(\Omeka\Entity\Value::class);
        $baseCriteria = new \Doctrine\Common\Collections\Criteria();
        $expr = $baseCriteria->expr();
        $baseCriteria
            ->where($expr->neq('value', null))
            ->andWhere($expr->neq('value', ''))
            ->orderBy(['id' => 'ASC'])
            ->setFirstResult(null)
            ->setMaxResults(self::SQL_LIMIT);

        $sql = 'SELECT COUNT(id) FROM value WHERE value IS NOT NULL and value != "";';
        $totalToProcess = $this->connection->query($sql)->fetchColumn();
        $this->logger->notice(
            'Checking {total} resource values with a value.', // @translate
            ['total' => $totalToProcess]
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
            $values = $this->valueRepository->matching($criteria);
            if (!$values->count() || $offset >= $values->count()) {
                break;
            }

            if ($offset) {
                $this->logger->info(
                    '{processed}/{total} values processed.', // @translate
                    ['processed' => $offset, 'total' => $totalToProcess]
                );

                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job was stopped.' // @translate
                    );
                    return false;
                }
            }

            /** @var \Omeka\Entity\Value $value */
            foreach ($values as $value) {
                ++$totalProcessed;

                $valueValue = $value->getValue();
                // Same, but may be useful for a more complex check.
                // $valueIso = mb_convert_encoding($valueValue, 'UTF-8', 'ISO-8859-15');
                $valueIso = utf8_decode($valueValue);
                if ($valueValue === $valueIso) {
                    // Don't log well formatted values because they are many.
                    ++$totalUtf8;
                    continue;
                }

                $valueValueEncoding = mb_detect_encoding($valueValue);
                $valueIsoEncoding = mb_detect_encoding($valueIso);

                // Of course, well formatted strings should remain.
                // if the original value and the converted value are valid utf-8
                // together, there is an issue!
                $valueValueIsUtf8 = preg_match('!!u', $valueValue);
                $valueIsoIsUtf8 = preg_match('!!u', $valueIso);
                if (!($valueValueIsUtf8 && $valueIsoIsUtf8)
                    || (strlen($valueIso) === mb_strlen($valueIso) && mb_strlen($valueIso) === mb_strlen($valueValue))
                    || $valueValueEncoding === 'ASCII'
                    || $valueIsoEncoding === 'ASCII'
                ) {
                    ++$totalUtf8;
                    continue;
                }

                $row = [
                    'resource' => $value->getResource()->getId(),
                    'value' => $value->getId(),
                    'term' => $this->properties[$value->getProperty()->getId()],
                    'content' => mb_substr(trim(str_replace(["\n", "\r", "\t"], ['  ', '  ', '  '], $value->getValue())), 0, 1000),
                    'fixed' => $fix ? $yes : '',
                ];

                if ($fix) {
                    // The iso value will be a utf8 value in the database.
                    $value->setValue($valueIso);
                    $this->entityManager->persist($value);
                    ++$totalSucceed;
                }

                if (--$maxRows >= 0) {
                    $this->writeRow($row);
                }
            }

            $this->entityManager->flush();
            $this->valueRepository->clear();
            unset($values);

            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed, {total_utf8} already utf-8, {total_succeed} converted.', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
                'total_utf8' => $totalUtf8,
                'total_succeed' => $totalSucceed,
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

<?php declare(strict_types=1);

namespace EasyAdmin\Job;

/**
 * Adapted:
 * @see \EasyAdmin\Job\DbValueClean
 * @see \BulkEdit\Mvc\Controller\Plugin\TrimValues
 * @see \BulkEdit\Mvc\Controller\Plugin\CleanEmptyValues
 * @see \BulkEdit\Mvc\Controller\Plugin\CleanLanguages
 * @see \BulkEdit\Mvc\Controller\Plugin\DeduplicateValues
 */
class DbValueClean extends AbstractCheck
{
    /**
     * @var bool
     */
    protected $supportAnyValue = false;

    public function perform(): void
    {
        $availableActions = [
            'trim',
            'null_empty_value',
            'null_empty_language',
            'deduplicate',
        ];

        $actions = $this->getArg('actions', []) ?: [];
        if (!$actions) {
            $this->logger->warn(
                'No action defined.' // @translate
            );
            return;
        }

        $actions = array_intersect($availableActions, $actions);
        if (!$actions) {
            $this->logger->warn(
                'No valid action defined.' // @translate
            );
            return;
        }

        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        // Allowed resource types or "all".
        $allowedResourceTypes = [
            'items',
            'item_sets',
            'media',
            'value_annotations',
            'annotations',
        ];

        $resourceTypes = $this->getArg('resource_types') ?: [];
        if (!$resourceTypes) {
            $this->logger->warn(
                'No resource type defined.' // @translate
            );
            return;
        }

        $allResourceTypes = in_array('all', $resourceTypes);
        if ($allResourceTypes) {
            $resourceTypes = $allowedResourceTypes;
        } elseif (count(array_intersect($allowedResourceTypes, $resourceTypes)) !== count($resourceTypes)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'There are invalid resource types to process: {resource_types}.', // @translate
                ['resource_types' => implode(', ', array_diff($resourceTypes, $allowedResourceTypes))]
            );
            return;
        } else {
            $resourceTypes = array_intersect($allowedResourceTypes, $resourceTypes);
            if ($resourceTypes === $allowedResourceTypes) {
                $allResourceTypes = true;
            }
        }

        $query = [];
        $queryArg = $this->getArg('query');
        if ($queryArg) {
            parse_str(ltrim((string) $queryArg, "? \t\n\r\0\x0B"), $query);
        }

        if ($query && ($allResourceTypes || count($resourceTypes) > 1)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'When a query is set, only one resource type can be processed.', // @translate
            );
            return;
        }

        if ($allResourceTypes) {
            $this->logger->info(
                'Resource types to process: all.' // @translate
            );
        } else {
            $this->logger->info(
                'Resource types to process: {resource_types}.', // @translate
                ['resource_types' => implode(', ', $resourceTypes)]
            );
        }

        $process = $this->getArg('process');
        // $processFix = $process === 'db_value_clean_fix';

        $this->supportAnyValue = $this->supportAnyValue();

        foreach ($allResourceTypes ? [null] : $resourceTypes as $resourceType) {
            $resourceIds = $resourceType && $query
                ? $this->api->search($resourceType, $query, ['returnScalar' => 'id'])->getContent()
                : null;

            if (in_array('trim', $actions)) {
                $this->logger->info(
                    'Processing trimming of values.' // @translate
                );
                $this->trimValues($resourceIds);
            }

            if (in_array('null_empty_value', $actions)) {
                $this->logger->info(
                    'Processing cleaning empty values.' // @translate
                );
                $this->cleanEmptyValues($resourceIds);
            }

            if (in_array('null_empty_language', $actions)) {
                $this->logger->info(
                    'Processing cleaning empty language.' // @translate
                );
                $this->cleanEmptyLanguages($resourceIds);
            }

            if (in_array('deduplicate', $actions)) {
                $this->logger->info(
                    'Processing deduplication of values.' // @translate
                );
                $this->deduplicateValues($resourceIds);
            }

            // Free memory.
            unset($resourceIds);
        }

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    /**
     * Trim specified or all resource values and remove values that are empty.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @return int Number of trimmed values.
     */
    protected function trimValues(?array $resourceIds = null): int
    {
        if ($resourceIds !== null) {
            $resourceIds = array_filter(array_map('intval', $resourceIds));
            if (!count($resourceIds)) {
                return 0;
            }
        }

        $idsString = $resourceIds === null ? '' : implode(',', $resourceIds);

        // Sql "trim" is for space " " only, not end of line, new line or tab.
        // So use regexp_replace, but it's available only with mysql ≥ 8.0.4 and
        // mariadb ≥ 10.0.5 and Omeka requires only 5.5.3.
        $db = $this->databaseVersion();

        // The entity manager can not be used directly, because it does not
        // manage regex.
        if (($db['db'] === 'mariadb' && version_compare($db['version'], '10.0.5', '>='))
            || ($db['db'] === 'mysql' && version_compare($db['version'], '8.0.4', '>='))
        ) {
            // The pattern is a full unicode one.
            $query = <<<'SQL'
                UPDATE `value` AS `v`
                SET
                    `v`.`value` = NULLIF(REGEXP_REPLACE(`v`.`value`, "^[\\s\\h\\v[:blank:][:space:]]+|[\\s\\h\\v[:blank:][:space:]]+$", ""), ""),
                    `v`.`lang` = NULLIF(REGEXP_REPLACE(`v`.`lang`, "^[\\s\\h\\v[:blank:][:space:]]+|[\\s\\h\\v[:blank:][:space:]]+$", ""), ""),
                    `v`.`uri` = NULLIF(REGEXP_REPLACE(`v`.`uri`, "^[\\s\\h\\v[:blank:][:space:]]+|[\\s\\h\\v[:blank:][:space:]]+$", ""), "")
                SQL;
        } else {
            // The pattern uses a simple trim.
            $query = <<<'SQL'
                UPDATE `value` AS `v`
                SET
                    `v`.`value` = NULLIF(TRIM(TRIM("\t" FROM TRIM("\n" FROM TRIM("\r" FROM TRIM("\n" FROM `v`.`value`))))), ""),
                    `v`.`lang` = NULLIF(TRIM(TRIM("\t" FROM TRIM("\n" FROM TRIM("\r" FROM TRIM("\n" FROM `v`.`lang`))))), ""),
                    `v`.`uri` = NULLIF(TRIM(TRIM("\t" FROM TRIM("\n" FROM TRIM("\r" FROM TRIM("\n" FROM `v`.`uri`))))), "")
                SQL;
        }

        if ($idsString) {
            $query .= "\n" . <<<SQL
                WHERE `v`.`resource_id` IN ($idsString)
                SQL;
        }

        $count = $this->connection->executeStatement($query);
        $this->logger->info(
            'Trimmed {count} values.', // @translate
            ['count' => $count]
        );
        $trimmed = $count;

        // Remove empty values, even if there is a language.
        $query = <<<'SQL'
            DELETE FROM `value`
            WHERE `value_resource_id` IS NULL
                AND `value` IS NULL
                AND `uri` IS NULL
                AND `value_annotation_id` IS NULL
            SQL;
        if ($idsString) {
            $query .= "\n" . <<<SQL
                    AND `resource_id` IN ($idsString)
                SQL;
        }

        $count = $this->connection->executeStatement($query);
        $this->logger->info(
            'Removed {count} empty string values after trimming.', // @translate
            ['count' => $count]
        );

        return (int) $trimmed;
    }

    /**
     * Set "null" when values, uri or linked resource is empty.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @return int Number of cleaned values.
     */
    protected function cleanEmptyValues(?array $resourceIds = null): int
    {
        if ($resourceIds !== null) {
            $resourceIds = array_filter(array_map('intval', $resourceIds));
            if (!count($resourceIds)) {
                return 0;
            }
        }

        // The entity manager may be used directly, but it is simpler with sql.
        $sql = <<<'SQL'
            UPDATE `value` AS `v`
            SET
                `v`.`value` = IF(`v`.`value` IS NULL OR `v`.`value` = "", NULL, `v`.`value`),
                `v`.`uri` = IF(`v`.`uri` IS NULL OR `v`.`uri` = "", NULL, `v`.`uri`)
            SQL;

        $idsString = $resourceIds === null ? '' : implode(',', $resourceIds);
        if ($idsString) {
            $sql .= "\n" . <<<SQL
                WHERE `v`.`resource_id` IN ($idsString)
                SQL;
        }

        $count = $this->connection->executeStatement($sql);
        $this->logger->info(
            'Updated empty values and uris of {count} values.', // @translate
            ['count' => $count]
        );

        return (int) $count;
    }

    /**
     * Set "null" when language is empty.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @return int Number of cleaned values.
     */
    protected function cleanEmptyLanguages(?array $resourceIds = null): int
    {
        if ($resourceIds !== null) {
            $resourceIds = array_filter(array_map('intval', $resourceIds));
            if (!count($resourceIds)) {
                return 0;
            }
        }

        // Use a direct query: during a post action, data are already flushed.
        // The entity manager may be used directly, but it is simpler with sql.

        $sql = <<<'SQL'
            UPDATE `value` AS `v`
            SET `v`.`lang` = NULL
            WHERE `v`.`lang` = ''
            SQL;

        $idsString = $resourceIds === null ? '' : implode(',', $resourceIds);
        if ($idsString) {
            $sql .= "\n" . <<<SQL
                AND `v`.`resource_id` IN ($idsString)
                SQL;
        }

        $count = $this->connection->executeStatement($sql);
        $this->logger->info(
            'Updated empty language of {count} values.', // @translate
            ['count' => $count]
        );

        return (int) $count;
    }

    /**
     * Deduplicate specified or all resource values.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @return int Number of deduplicated values.
     */
    protected function deduplicateValues(?array $resourceIds = null): int
    {
        if ($resourceIds !== null) {
            $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
            if (!count($resourceIds)) {
                return 0;
            }
        }

        $bind = [];
        $types = [];

        // The query modifies the sql mode, so it should be reset.
        $sqlMode = $this->connection->fetchOne('SELECT @@SESSION.sql_mode;');

        // For large base, a temporary table is preferred to speed process.
        // ANY_VALUE is needed for MySQL's ONLY_FULL_GROUP_BY mode (MIN/MAX won't work here).
        if ($this->supportAnyValue) {
            $prefix = 'ANY_VALUE(';
            $suffix = ')';
        } else {
            $prefix = $suffix = '';
        }

        if ($resourceIds) {
            // For specified values.
            $sqlWhere1 = 'AND `resource_id` IN (:resource_ids)';
            $sqlWhere2 = 'AND `v`.`resource_id` IN (:resource_ids)';
            $bind['resource_ids'] = $resourceIds;
            $types['resource_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        } else {
            // For all values.
            $sqlWhere1 = '';
            $sqlWhere2 = '';
        }

        // Get the total count of values.
        $sqlCount = 'SELECT COUNT(*) FROM `value`';
        $totalCount = $this->connection->fetchOne($sqlCount);

        $sql = <<<SQL
            SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''));
            DROP TEMPORARY TABLE IF EXISTS `value_temporary`;
            CREATE TEMPORARY TABLE `value_temporary` (`id` INT, PRIMARY KEY (`id`))
            AS
                SELECT $prefix`id`$suffix
                FROM `value`
                WHERE 1 = 1
                    $sqlWhere1
                GROUP BY `resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`, `value_annotation_id`;
            DELETE `v`
            FROM `value` AS `v`
            LEFT JOIN `value_temporary` AS `value_temporary`
                ON `value_temporary`.`id` = `v`.`id`
            WHERE `value_temporary`.`id` IS NULL
                $sqlWhere2;
            DROP TEMPORARY TABLE IF EXISTS `value_temporary`;
            SQL;

        $this->connection->executeStatement($sql, $bind, $types);
        $this->connection->executeStatement("SET sql_mode = '$sqlMode';");

        // Output the right number of deduplicated values.
        $sqlCount = 'SELECT COUNT(*) FROM `value`';
        $count = $totalCount - (int) $this->connection->fetchOne($sqlCount);

        $this->logger->info(
            'Deduplicated {count} values.', // @translate
            ['count' => $count]
        );

        return (int) $count;
    }

    /**
     * Get  the version of the database.
     *
     * @return array with keys "db" and "version".
     */
    protected function databaseVersion()
    {
        $result = [
            'db' => '',
            'version' => '',
        ];

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->entityManager->getConnection();

        $sql = 'SHOW VARIABLES LIKE "version";';
        $version = $connection->executeQuery($sql)->fetchAllKeyValue();
        $version = reset($version);

        $isMySql = stripos($version, 'mysql') !== false;
        if ($isMySql) {
            $result['db'] = 'mysql';
            $result['version'] = $version;
            return $result;
        }

        $isMariaDb = stripos($version, 'mariadb') !== false;
        if ($isMariaDb) {
            $result['db'] = 'mariadb';
            $result['version'] = $version;
            return $result;
        }

        $sql = 'SHOW VARIABLES LIKE "innodb_version";';
        $version = $connection->executeQuery($sql)->fetchAllKeyValue();
        $version = reset($version);
        $isInnoDb = !empty($version);
        if ($isInnoDb) {
            $result['db'] = 'innodb';
            $result['version'] = $version;
            return $result;
        }

        return $result;
    }

    protected function supportAnyValue(): bool
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');

        // To do a request is the simpler way to check if the flag ONLY_FULL_GROUP_BY
        // is set in any databases, systems and versions and that it can be
        // bypassed by Any_value().
        $sql = 'SELECT ANY_VALUE(id) FROM user LIMIT 1;';
        try {
            $connection->executeQuery($sql)->fetchOne();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

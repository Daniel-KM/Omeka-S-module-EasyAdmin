<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbCustomVocabMissingItemSets extends AbstractCheck
{
    /**
     * @var string
     */
    protected $table = 'custom_vocab';

    public function perform(): void
    {
        parent::perform();

        if (!$this->checkTableExists('custom_vocab')) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'There is no table "{table}" to check.', // @translate
                ['table' => $this->table]
            );
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_customvocab_missing_itemsets_clean';

        $remove = $this->getArg('mode') === 'remove';
        if ($remove) {
            $this->logger->info(
                'The custom vocab with missing item sets will be removed.' // @translate
            );
        } else {
            $this->logger->info(
                'The custom vocab with missing item sets will be updated as simple empty list.' // @translate
            );
        }

        $this->checkCustomVocabMissingItemSets($processFix, $remove);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function checkTableExists(string $table): bool
    {
        $dbname = $this->connection->getDatabase();
        $table = $this->connection->quote($table);
        $sql = <<<SQL
SELECT *
FROM information_schema.TABLES
WHERE table_schema = "$dbname"
    AND table_name = $table
LIMIT 1;
SQL;
        return (bool) $this->connection->executeQuery($sql)->fetchOne();
    }

    /**
     * Check missing item sets in the db table "custom_vocab".
     *
     * @param bool $fix
     * @param bool $remove
     */
    protected function checkCustomVocabMissingItemSets(bool $fix = false, bool $remove = false): void
    {
        $sqlList = <<<SQL
SELECT `custom_vocab`.`id`, `custom_vocab`.`label`
FROM `custom_vocab`
LEFT JOIN `item_set` ON `item_set`.`id` = `custom_vocab`.`item_set_id`
WHERE `custom_vocab`.`item_set_id` IS NOT NULL
    AND `custom_vocab`.`terms` IS NULL
    AND `custom_vocab`.`uris` IS NULL
    AND `item_set`.`id` = NULL
;
SQL;
        $result = $this->connection->executeQuery($sqlList)->fetchAllKeyValue();

        if (!count($result)) {
            $this->logger->notice(
                'There is no custom vocab with a missing item set.' // @translate
            );
            return;
        }

        $this->logger->notice(
            'There are {count} custom vocabs that references a missing item set: {item_sets}.', // @translate
            ['count' => count($result), 'item_sets' => implode(', ', $result)]
        );

        if ($fix && $remove) {
            $sql = <<<SQL
DELETE `custom_vocab` 
FROM `custom_vocab`
LEFT JOIN `item_set` ON `item_set`.`id` = `custom_vocab`.`item_set_id`
WHERE `custom_vocab`.`item_set_id` IS NOT NULL
    AND `custom_vocab`.`terms` IS NULL
    AND `custom_vocab`.`uris` IS NULL
    AND `item_set`.`id` = NULL
;
SQL;
            $this->connection->executeStatement($sql);
            $this->logger->notice(
                'Missing item sets of custom vocabs were removed.' // @translate
            );
        } elseif ($fix) {
            $sql = <<<SQL
UPDATE `custom_vocab`
LEFT JOIN `item_set` ON `item_set`.`id` = `custom_vocab`.`item_set_id`
SET
    `item_set_id` = NULL,
    `terms` = "{}"
WHERE `custom_vocab`.`item_set_id` IS NOT NULL
    AND `custom_vocab`.`terms` IS NULL
    AND `custom_vocab`.`uris` IS NULL
    AND `item_set`.`id` = NULL
;
SQL;
            $this->connection->executeStatement($sql);
            $this->logger->notice(
                'Missing item sets of custom vocabs were replaced by standard empty custom vocabs.' // @translate
            );
        }
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbResourceInvalid extends AbstractCheck
{
    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_resource_invalid_fix';

        $this->checkResourceInvalid($processFix);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    /**
     * Check if all resource types are valid.
     */
    protected function checkResourceInvalid(bool $fix): bool
    {
        $sqlCount = <<<'SQL'
            SELECT `resource`.`id`, `resource`.`resource_type`
            FROM `resource`
            LEFT JOIN `item` ON `item`.`id` = `resource`.`id`
            LEFT JOIN `item_set` ON `item_set`.`id` = `resource`.`id`
            LEFT JOIN `media` ON `media`.`id` = `resource`.`id`
            LEFT JOIN `value_annotation` ON `value_annotation`.`id` = `resource`.`id`
            WHERE (`item`.`id` IS NOT NULL AND `resource`.`resource_type` != 'Omeka\Entity\Item')
                OR (`item_set`.`id` IS NOT NULL AND `resource`.`resource_type` != 'Omeka\Entity\ItemSet')
                OR (`media`.`id` IS NOT NULL AND `resource`.`resource_type` != 'Omeka\Entity\Media')
                OR (`value_annotation`.`id` IS NOT NULL AND `resource`.`resource_type` != 'Omeka\Entity\ValueAnnotation')
            ;
            SQL;
        $result = $this->connection->executeQuery($sqlCount)->fetchAllKeyValue();

        if (!$result) {
            $this->logger->notice(
                'There are no resources with invalid resource type.' // @translate
            );
            return true;
        }

        $this->logger->notice(
            'There are {count} resources with invalid resource type: {json}.', // @translate
            ['count' => count($result), 'json' => json_encode($result, 448)]
        );

        if (!$fix || !$result) {
            return true;
        }

        // Do the update.
        $sql = <<<'SQL'
            UPDATE `resource`
            LEFT JOIN `item` ON `item`.`id` = `resource`.`id`
            LEFT JOIN `item_set` ON `item_set`.`id` = `resource`.`id`
            LEFT JOIN `media` ON `media`.`id` = `resource`.`id`
            LEFT JOIN `value_annotation` ON `value_annotation`.`id` = `resource`.`id`
            SET
                `resource_type` =
                    CASE
                        WHEN `item`.`id` IS NOT NULL
                            THEN 'Omeka\Entity\Item'
                        WHEN `item_set`.`id` IS NOT NULL
                            THEN 'Omeka\Entity\ItemSet'
                        WHEN `media`.`id` IS NOT NULL
                            THEN 'Omeka\Entity\Media'
                        WHEN `value_annotation`.`id` IS NOT NULL
                            THEN 'Omeka\Entity\ValueAnnotation'
                        ELSE `resource_type`
                    END
            WHERE (`item`.`id` IS NOT NULL AND `resource`.`resource_type` != 'Omeka\Entity\Item')
                OR (`item_set`.`id` IS NOT NULL AND `resource`.`resource_type` != 'Omeka\Entity\ItemSet')
                OR (`media`.`id` IS NOT NULL AND `resource`.`resource_type` != 'Omeka\Entity\Media')
                OR (`value_annotation`.`id` IS NOT NULL AND `resource`.`resource_type` != 'Omeka\Entity\ValueAnnotation')
            ;
            SQL;
        $result = $this->connection->executeStatement($sql);

        $newList = $this->connection->executeQuery($sqlCount)->fetchAllKeyValue();

        if (!count($newList)) {
            $this->logger->notice(
                '{count} resources with invalid resource type were fixed.', // @translate
                ['count' => (int) $result]
            );
            return true;
        }

        $this->logger->notice(
            'There are {count} resources with invalid resource type that cannot be fixed automatically: {json}.', // @translate
            ['count' => count($result), 'json' => json_encode($newList, 448)]
        );

        return false;
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbResourceIncomplete extends AbstractCheck
{
    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_resource_incomplete_fix';

        $this->checkResourceIncomplete($processFix);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    /**
     * Check if all resource are complete (have a specific resource).
     */
    protected function checkResourceIncomplete(bool $fix): bool
    {
        // Use a try-catch block to fix cases during upgrade/disable.

        // $hasValueAnnotation = version_compare(\Omeka\Module::VERSION, '3.2', '>=');
        try {
            $this->connection->executeQuery('SELECT id FROM value_annotation')->fetchOne();
            $joinValueAnnotation = 'LEFT JOIN `value_annotation` ON `value_annotation`.`id` = `resource`.`id`';
            $whereValueAnnotation = 'AND `value_annotation`.`id` IS NULL';
        } catch (\Exception $e) {
            $joinValueAnnotation = '';
            $whereValueAnnotation = '';
        }

        // $hasAnnotation = $this->isModuleActive('Annotate');
        try {
            $this->connection->executeQuery('SELECT id FROM annotation')->fetchOne();
            $joinAnnotation = 'LEFT JOIN `annotation` ON `annotation`.`id` = `resource`.`id`';
            $whereAnnotation = 'AND `annotation`.`id` IS NULL';
        } catch (\Exception $e) {
            $joinAnnotation = '';
            $whereAnnotation = '';
        }

        // Old versions of module Annotate has four tables.
        if ($joinAnnotation) {
            try {
                $this->connection->executeQuery('SELECT id FROM annotation_part')->fetchOne();
                $joinAnnotation .= "\n" . 'LEFT JOIN `annotation_part` ON `annotation_part`.`id` = `resource`.`id`'
                    . "\n" . 'LEFT JOIN `annotation_body` ON `annotation_body`.`id` = `resource`.`id`'
                    . "\n" . 'LEFT JOIN `annotation_target` ON `annotation_target`.`id` = `resource`.`id`';
                $whereAnnotation .= "\n" . '    AND `annotation_part`.`id` IS NULL'
                    . "\n" . '    AND `annotation_body`.`id` IS NULL'
                    . "\n" . '    AND `annotation_target`.`id` IS NULL';
            } catch (\Exception $e) {
                // Nothing to do.
            }
        }

        $totalResources = $this->connection->executeQuery('SELECT COUNT(`resource`.`id`) FROM `resource`')->fetchOne();

        $sql = <<<SQL
SELECT COUNT(`resource`.`id`)
FROM `resource`
LEFT JOIN `item` ON `item`.`id` = `resource`.`id`
LEFT JOIN `item_set` ON `item_set`.`id` = `resource`.`id`
LEFT JOIN `media` ON `media`.`id` = `resource`.`id`
$joinValueAnnotation
$joinAnnotation
WHERE `item`.`id` IS NULL
    AND `item_set`.`id` IS NULL
    AND `media`.`id` IS NULL
    $whereValueAnnotation
    $whereAnnotation
;
SQL;
        $result = $this->connection->executeQuery($sql)->fetchOne();
        $this->logger->notice(
            'There are {count}/{total} resources that are not specified.', // @translate
            ['count' => (int) $result, 'total' => (int) $totalResources]
        );

        if (!$fix || !$result) {
            return true;
        }

        // Do the update.
        $sql = <<<SQL
DELETE `resource`
FROM `resource`
LEFT JOIN `item` ON `item`.`id` = `resource`.`id`
LEFT JOIN `item_set` ON `item_set`.`id` = `resource`.`id`
LEFT JOIN `media` ON `media`.`id` = `resource`.`id`
$joinValueAnnotation
$joinAnnotation
WHERE `item`.`id` IS NULL
    AND `item_set`.`id` IS NULL
    AND `media`.`id` IS NULL
    $whereValueAnnotation
    $whereAnnotation
;
SQL;
        $result = $this->connection->executeStatement($sql);
        $this->logger->notice(
            '{count}/{total} resources that were not specified were removed.', // @translate
            ['count' => (int) $result, 'total' => (int) $totalResources]
        );
        return true;
    }
}
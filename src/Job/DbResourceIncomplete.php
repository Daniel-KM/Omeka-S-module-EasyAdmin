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
        $tables = $this->getResourceSubTables();

        $joins = [];
        $wheres = [];
        foreach (array_keys($tables) as $table) {
            $joins[] = sprintf('LEFT JOIN `%1$s` ON `%1$s`.`id` = `resource`.`id`', $table);
            $wheres[] = sprintf('`%s`.`id` IS NULL', $table);
        }

        // Old versions of module Annotate had three additional tables.
        if (isset($tables['annotation'])) {
            foreach (['annotation_part', 'annotation_body', 'annotation_target'] as $legacy) {
                try {
                    $this->connection->executeQuery(sprintf('SELECT 1 FROM `%s` LIMIT 1', $legacy));
                    $joins[] = sprintf('LEFT JOIN `%1$s` ON `%1$s`.`id` = `resource`.`id`', $legacy);
                    $wheres[] = sprintf('`%s`.`id` IS NULL', $legacy);
                } catch (\Throwable $e) {
                    // Skip.
                }
            }
        }

        $joinsSql = implode("\n", $joins);
        $wheresSql = implode("\n            AND ", $wheres);

        $totalResources = $this->connection->executeQuery('SELECT COUNT(`resource`.`id`) FROM `resource`')->fetchOne();

        $sql = <<<SQL
            SELECT COUNT(`resource`.`id`)
            FROM `resource`
            $joinsSql
            WHERE $wheresSql
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

        $sql = <<<SQL
            DELETE `resource`
            FROM `resource`
            $joinsSql
            WHERE $wheresSql
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

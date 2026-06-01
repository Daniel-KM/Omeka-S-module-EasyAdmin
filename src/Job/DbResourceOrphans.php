<?php declare(strict_types=1);

namespace EasyAdmin\Job;

/**
 * Check (and optionally remove) rows in resource sub-tables (item, item_set,
 * media, value_annotation, annotation, digital_object, …) without a matching
 * row in `resource`. Such orphans cannot happen through normal use (FK ON
 * DELETE CASCADE) but may appear after a partial SQL import, a crash, or a
 * manual deletion.
 */
class DbResourceOrphans extends AbstractCheck
{
    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_resource_orphans_fix';

        $this->checkResourceOrphans($processFix);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    protected function checkResourceOrphans(bool $fix): bool
    {
        $tables = $this->getResourceSubTables();

        $totalsByTable = [];
        foreach (array_keys($tables) as $table) {
            $sql = <<<SQL
                SELECT COUNT(`$table`.`id`)
                FROM `$table`
                LEFT JOIN `resource` ON `resource`.`id` = `$table`.`id`
                WHERE `resource`.`id` IS NULL
                ;
                SQL;
            $count = (int) $this->connection->executeQuery($sql)->fetchOne();
            $totalsByTable[$table] = $count;
        }

        $grandTotal = array_sum($totalsByTable);

        if (!$grandTotal) {
            $this->logger->notice(
                'No orphan rows found in sub-tables of `resource` ({tables}).', // @translate
                ['tables' => implode(', ', array_keys($tables))]
            );
            return true;
        }

        foreach ($totalsByTable as $table => $count) {
            if ($count) {
                $this->logger->warn(
                    'Table `{table}`: {count} orphan rows without matching `resource`.', // @translate
                    ['table' => $table, 'count' => $count]
                );
            }
        }

        if (!$fix) {
            return true;
        }

        $totalDeleted = 0;
        foreach ($totalsByTable as $table => $count) {
            if (!$count) {
                continue;
            }
            $sql = <<<SQL
                DELETE `$table`
                FROM `$table`
                LEFT JOIN `resource` ON `resource`.`id` = `$table`.`id`
                WHERE `resource`.`id` IS NULL
                ;
                SQL;
            $deleted = (int) $this->connection->executeStatement($sql);
            $totalDeleted += $deleted;
            $this->logger->notice(
                'Table `{table}`: {count} orphan rows removed.', // @translate
                ['table' => $table, 'count' => $deleted]
            );
        }

        $this->logger->notice(
            '{count} orphan rows removed across all sub-tables.', // @translate
            ['count' => $totalDeleted]
        );

        return true;
    }
}

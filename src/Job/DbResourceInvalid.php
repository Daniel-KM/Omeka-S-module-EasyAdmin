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
        $tables = $this->getResourceSubTables();

        $joins = [];
        $orWheres = [];
        $caseLines = [];
        foreach ($tables as $table => $class) {
            $classEscaped = str_replace('\\', '\\\\', $class);
            $joins[] = sprintf('LEFT JOIN `%1$s` ON `%1$s`.`id` = `resource`.`id`', $table);
            $orWheres[] = sprintf("(`%s`.`id` IS NOT NULL AND `resource`.`resource_type` != '%s')", $table, $classEscaped);
            $caseLines[] = sprintf("WHEN `%s`.`id` IS NOT NULL THEN '%s'", $table, $classEscaped);
        }

        $joinsSql = implode("\n            ", $joins);
        $whereSql = implode("\n                OR ", $orWheres);
        $caseSql = implode("\n                        ", $caseLines);

        $sqlCount = <<<SQL
            SELECT `resource`.`id`, `resource`.`resource_type`
            FROM `resource`
            $joinsSql
            WHERE $whereSql
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

        if (!$fix) {
            return true;
        }

        $sql = <<<SQL
            UPDATE `resource`
            $joinsSql
            SET
                `resource_type` =
                    CASE
                        $caseSql
                        ELSE `resource_type`
                    END
            WHERE $whereSql
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
            ['count' => count($newList), 'json' => json_encode($newList, 448)]
        );

        return false;
    }
}

<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class DbItemPrimaryMedia extends AbstractCheck
{
    public function perform(): void
    {
        parent::perform();

        if (version_compare(\Omeka\Module::VERSION, '4', '<')) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'This task requires Omeka S v4.' // @translate
            );
        }

        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');
        $processFix = $process === 'db_item_primary_media_fix';

        $this->checkDbPrimaryMedia($processFix);

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    /**
     * Check the primary media of items.
     */
    protected function checkDbPrimaryMedia(bool $fix): bool
    {
        $sql = <<<'SQL'
SELECT COUNT(`item`.`id`)
FROM `item`
INNER JOIN `media` ON `media`.`item_id` = `item`.`id`
WHERE `item`.`primary_media_id` IS NULL
;
SQL;
        $result = $this->connection->executeQuery($sql)->fetchOne();
        $this->logger->notice(
            'There are {total} items without primary media.', // @translate
            ['total' => (int) $result]
        );

        if (!$fix) {
            return true;
        }

        // Do the update.
        $sql = <<<'SQL'
UPDATE `item`
INNER JOIN `media` ON `media`.`item_id` = `item`.`id`
SET
    `item`.`primary_media_id` = `media`.`id`
WHERE `item`.`primary_media_id` IS NULL
    AND `media`.`position` = 1
;
SQL;

        $result = $this->connection->executeStatement($sql);
        $this->logger->notice(
            '{total} primary medias were set.', // @translate
            ['total' => (int) $result]
        );
        return true;
    }
}
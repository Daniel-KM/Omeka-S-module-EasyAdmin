<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class MediaPosition extends AbstractCheck
{
    public function perform(): void
    {
        parent::perform();

        $process = $this->getArg('process');

        $this->checkMediaPosition($process === 'media_position_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function checkMediaPosition($fix = false)
    {
        $sql = 'SELECT COUNT(id) FROM media;';
        $totalResources = $this->connection->executeQuery($sql)->fetchOne();
        if (empty($totalResources)) {
            $this->logger->notice(
                'No media to process.' // @translate
            );
            return true;
        }

        $sql = 'SELECT COUNT(id) FROM item;';
        $totalToProcess = $this->connection->executeQuery($sql)->fetchOne();
        if (empty($totalResources)) {
            $this->logger->notice(
                'No item to process.' // @translate
            );
            return true;
        }

        // Do the process.

        $offset = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        while (true) {
            $items = $this->itemRepository->findBy([], ['id' => 'ASC'], self::SQL_LIMIT, $offset);
            if (!count($items)) {
                break;
            }

            if ($offset) {
                $this->logger->info(
                    '{processed}/{total} items processed.', // @translate
                    ['processed' => $offset, 'total' => $totalToProcess]
                );

                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job was stopped.' // @translate
                    );
                    return false;
                }
            }

            $result = [];

            /** @var \Omeka\Entity\Item $item */
            foreach ($items as $item) {
                // Media are automatically ordered (cf. item entity).
                $medias = $item->getMedia();
                if (!count($medias)) {
                    ++$totalProcessed;
                    continue;
                }

                $position = 0;
                /** @var \Omeka\Entity\Media $media */
                foreach ($medias as $media) {
                    ++$position;
                    if ($media->getPosition() !== $position) {
                        $result[] = $item->getId();
                        if ($fix) {
                            $media->setPosition($position);
                            $this->entityManager->persist($media);
                        } else {
                            break;
                        }
                    }
                }

                ++$totalProcessed;
            }

            if ($fix) {
                $this->entityManager->flush();
                foreach ($result as $id) {
                    ++$totalSucceed;
                    $this->logger->notice(
                        'Fixed item #{item_id} wrong media positions.', // @translate
                        ['item_id' => $id]
                    );
                }
            } else {
                foreach ($result as $id) {
                    ++$totalSucceed;
                    $this->logger->notice(
                        'Item #{item_id} has wrong media positions.', // @translate
                        ['item_id' => $id]
                    );
                }
            }

            // Avoid memory issue.
            unset($item);
            unset($media);
            $this->entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        if ($fix) {
            $this->logger->notice(
                'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed.', // @translate
                [
                    'processed' => $totalProcessed,
                    'total' => $totalToProcess,
                    'total_succeed' => $totalSucceed,
                    'total_failed' => $totalFailed,
                ]
            );
        } else {
            $this->logger->notice(
                'End of process: {processed}/{total} processed, {total_succeed} items has wrong positions.', // @translate
                [
                    'processed' => $totalProcessed,
                    'total' => $totalToProcess,
                    'total_succeed' => $totalSucceed,
                ]
            );
        }
    }
}

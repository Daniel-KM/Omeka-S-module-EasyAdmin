<?php
namespace BulkCheck\Job;

use Doctrine\Common\Collections\Criteria;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class FileDerivative extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    public function perform()
    {
        /**
         * @var array $config
         * @var \Omeka\Mvc\Controller\Plugin\Logger $logger
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\File\TempFileFactory $tempFileFactory
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Doctrine\DBAL\Connection $connection
         */
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        // The api cannot update value "has_thumbnails", so use entity manager.
        $entityManager = $services->get('Omeka\EntityManager');
        $connection = $entityManager->getConnection();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Zend\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('derivative/images/job_' . $this->job->getId());

        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $types = array_keys($config['thumbnails']['types']);

        // Prepare the list of medias.

        $repository = $entityManager->getRepository(\Omeka\Entity\Media::class);
        $criteria = Criteria::create();
        $expr = $criteria->expr();

        // Always true expression to simplify process.
        $criteria->where($expr->gt('id', 0));

        $itemSets = $this->getArg('item_sets', []);
        if ($itemSets) {
            // TODO Include dql as a subquery.
            $dql = <<<DQL
SELECT item.id
FROM Omeka\Entity\Item item
JOIN item.itemSets item_set
WHERE item_set.id IN (:item_set_ids)
DQL;
            $query = $entityManager->createQuery($dql);
            $query->setParameter('item_set_ids', $itemSets, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);
            $itemIds = array_column($query->getArrayResult(), 'id');
            $criteria->andWhere($expr->in('item', $itemIds));
        }

        $ingesters = $this->getArg('ingesters', []);
        if ($ingesters && !in_array('', $ingesters)) {
            $criteria->andWhere($expr->in('ingester', $ingesters));
        }

        $renderers = $this->getArg('renderers', []);
        if ($renderers && !in_array('', $renderers)) {
            $criteria->andWhere($expr->in('renderer', $renderers));
        }

        $mediaTypes = $this->getArg('media_types', []);
        if ($mediaTypes && !in_array('', $mediaTypes)) {
            $criteria->andWhere($expr->in('mediaType', $mediaTypes));
        }

        $mediaIds = $this->getArg('media_ids');
        if ($mediaIds) {
            $range = $this->exprRange('id', $mediaIds);
            if ($range) {
                $criteria->andWhere($expr->orX(...$range));
            }
        }

        $withoutThumbnails = $this->getArg('original_without_thumbnails');
        if ($withoutThumbnails) {
            $criteria->andWhere($expr->eq('hasThumbnails', 0));
        }

        $totalResources = $api->search('media', ['limit' => 1])->getTotalResults();

        // TODO Manage creation of thumbnails for media without original (youtube…).
        // Check only media with an original file.
        $criteria->andWhere($expr->eq('hasOriginal', 1));

        $criteria->orderBy(['id' => 'ASC']);

        $collection = $repository->matching($criteria);
        $totalToProcess = $collection->count();

        if (empty($totalToProcess)) {
            $logger->info(new Message(
                'No media to process for creation of derivative files (on a total of %d medias). You may check your query.', // @translate
                $totalResources
            ));
            return;
        }

        $logger->info(new Message(
            'Processing creation of derivative files of %d medias (on a total of %d medias).', // @translate
            $totalToProcess, $totalResources
        ));

        // Do the process.

        $offset = 0;
        $key = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        $count = 0;
        while (++$count <= $totalToProcess) {
            // Entity are used, because it's not possible to update the value
            // "has_thumbnails" via api.
            $criteria
                ->setMaxResults(self::SQL_LIMIT)
                ->setFirstResult($offset);
            $medias = $repository->matching($criteria);
            if (!count($medias)) {
                break;
            }

            /** @var \Omeka\Entity\Media $media */
            foreach ($medias as $key => $media) {
                if ($this->shouldStop()) {
                    $logger->warn(new Message(
                        'The job "Derivative Images" was stopped: %1$d/%2$d resources processed.', // @translate
                        $offset + $key, $totalToProcess
                    ));
                    break 2;
                }

                // Thumbnails are created only if the original file exists.
                $filename = $media->getFilename();
                $sourcePath = $basePath . '/original/' . $filename;

                if (!file_exists($sourcePath)) {
                    $logger->warn(new Message(
                        'Media #%d (%d/%d): the original file "%s" does not exist.', // @translate
                        $media->getId(), $offset + $key + 1, $totalToProcess, $filename
                    ));
                    continue;
                }

                if (!is_readable($sourcePath)) {
                    $logger->warn(new Message(
                        'Media #%d (%d/%d): the original file "%s" is not readable.', // @translate
                        $media->getId(), $offset + $key + 1, $totalToProcess, $filename
                    ));
                    continue;
                }

                // Check the current files.
                foreach ($types as $type) {
                    $derivativePath = $basePath . '/' . $type . '/' . $filename;
                    if (file_exists($derivativePath) && !is_writeable($derivativePath)) {
                        $logger->warn(new Message(
                            'Media #%d (%d/%d): derivative file "%s" is not writeable (type "%s").', // @translate
                            $media->getId(), $offset + $key + 1, $totalToProcess, $filename, $type
                        ));
                        $offset += self::SQL_LIMIT;
                        continue 2;
                    }
                }

                $logger->info(new Message(
                    'Media #%d (%d/%d): creating derivative files.', // @translate
                    $media->getId(), $offset + $key + 1, $totalToProcess
                ));

                $tempFile = $tempFileFactory->build();
                $tempFile->setTempPath($sourcePath);
                $tempFile->setStorageId($media->getStorageId());

                $hasThumbnails = $media->hasThumbnails();
                $result = $tempFile->storeThumbnails();
                if ($hasThumbnails !== $result) {
                    $media->setHasThumbnails($result);
                    $entityManager->persist($media);
                    $entityManager->flush();
                }

                ++$totalProcessed;

                if ($result) {
                    ++$totalSucceed;
                    $logger->info(new Message(
                        'Media #%d (%d/%d): derivative files created.', // @translate
                        $media->getId(), $offset + $key + 1, $totalToProcess
                    ));
                } else {
                    ++$totalFailed;
                    $logger->notice(new Message(
                        'Media #%d (%d/%d): derivative files not created.', // @translate
                        $media->getId(), $offset + $key + 1, $totalToProcess
                    ));
                }

                // Avoid memory issue.
                unset($media);
            }

            // Avoid memory issue.
            unset($medias);
            $entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        $logger->info(new Message(
            'End of the creation of derivative files: %d/%d processed, %d skipped, %d succeed, %d failed.', // @translate
            $totalProcessed, $totalToProcess, $totalToProcess - $totalProcessed, $totalSucceed, $totalFailed
        ));
    }

    /**
     * Create a doctrine expression for a range.
     *
     * @param string $column
     * @param array|string $ids
     * @return \Doctrine\Common\Collections\Expr\CompositeExpression|null
     */
    protected function exprRange($column, $ids)
    {
        $ranges = $this->rangeToArray($ids);
        if (empty($ranges)) {
            return [];
        }

        $conditions = [];

        $expr = Criteria::create()->expr();
        foreach ($ranges as $range) {
            if (strpos($range, '-')) {
                $from = strtok($range, '-');
                $to = strtok('-');
                if ($from && $to) {
                    $conditions[] = $expr->andX($expr->gte($column, $from), $expr->lte($column, $to));
                } elseif ($from) {
                    $conditions[] = $expr->gte($column, $from);
                } else {
                    $conditions[] = $expr->lte($column, $to);
                }
            } else {
                $conditions[] = $expr->eq($column, $range);
            }
        }

        return $conditions;
    }

    /**
     * Clean a list of ranges of ids.
     *
     * @param string|array $ids
     * @return array
     */
    protected function rangeToArray($ids)
    {
        $clean = function ($str) {
            $str = preg_replace('/[^0-9-]/', ' ', $str);
            $str = preg_replace('/\s*-+\s*/', '-', $str);
            $str = preg_replace('/-+/', '-', $str);
            $str = preg_replace('/\s+/', ' ', $str);
            return trim($str);
        };

        $ids = is_array($ids)
            ? array_map($clean, $ids)
            : explode(' ', $clean($ids));

        // Skip empty ranges and ranges with multiple "-".
        return array_values(array_filter($ids, function ($v) {
            return !empty($v) && substr_count($v, '-') <= 1;
        }));
    }
}

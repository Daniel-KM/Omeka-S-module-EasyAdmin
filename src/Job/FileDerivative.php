<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Doctrine\Common\Collections\Criteria;

class FileDerivative extends AbstractCheck
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate,
        'exists' => 'Exists', // @translate
        'has_thumbnails' => 'Has thumbnails', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        // The api cannot update value "has_thumbnails", so use entity manager.

        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        /**
         * @var \Omeka\File\TempFileFactory $tempFileFactory
         */
        $services = $this->getServiceLocator();
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');

        $basePath = $this->config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $types = array_keys($this->config['thumbnails']['types']);

        // TODO Add a check job or merge with files check.
        // $fix = true;

        // Prepare the list of medias.

        // Entity are used, because it's not possible to update the value
        // "has_thumbnails" via api.
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
            $query = $this->entityManager->createQuery($dql);
            $query->setParameter('item_set_ids', $itemSets, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);
            $itemIds = array_map('intval', array_column($query->getArrayResult(), 'id'));
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

        $totalResources = $this->api->search('media', ['limit' => 0])->getTotalResults();

        // TODO Manage creation of thumbnails for media without original (youtube…).
        // Check only media with an original file.
        $criteria
            ->andWhere($expr->eq('hasOriginal', 1))
            ->orderBy(['id' => 'ASC'])
            ->setMaxResults(self::SQL_LIMIT);

        $collection = $this->mediaRepository->matching($criteria);
        $totalToProcess = $collection->count();

        if (empty($totalToProcess)) {
            $this->logger->info(
                'No media to process for creation of derivative files (on a total of {total} medias). You may check your query.', // @translate
                ['total' => $totalResources]
            );
            return;
        }

        $this->logger->info(
            'Processing creation of derivative files of {total_process} medias (on a total of {total} medias).', // @translate
            ['total_process' => $totalToProcess, 'total' => $totalResources]
        );

        // Do the process.

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate
        $notReadable = $translator->translate('Not readable'); // @translate
        $notWriteable = $translator->translate('Not writeable'); // @translate
        $failed = $translator->translate('Failed'); // @translate

        $offset = 0;
        $key = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        $count = 0;
        while (++$count <= $totalToProcess) {
            $criteria
                ->setFirstResult($offset);
            $medias = $this->mediaRepository->matching($criteria);
            if (!$medias->count() || $offset >= $medias->count()) {
                break;
            }

            /** @var \Omeka\Entity\Media $media */
            foreach ($medias as $key => $media) {
                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job "Derivative Images" was stopped: {count]/{total} resources processed.', // @translate
                        ['count' => $offset + $key, 'total' => $totalToProcess]
                    );
                    break 2;
                }

                $row = [
                    'item' => $media->getItem()->getId(),
                    'media' => $media->getId(),
                    'filename' => $media->getFilename(),
                    'extension' => $media->getExtension() ?: '',
                    'exists' => '',
                    'has_thumbnails' => '',
                    'fixed' => '',
                ];

                // Thumbnails are created only if the original file exists.
                $filename = $media->getFilename();
                $sourcePath = $basePath . '/original/' . $filename;

                if (!file_exists($sourcePath)) {
                    $this->logger->warn(
                        'Media #{media_id} ({index}/{total}): the original file "{filename}" does not exist.', // @translate
                        ['media_id' => $media->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess, 'filename' => $filename]
                    );
                    $row['exists'] = $no;
                    $this->writeRow($row);
                    continue;
                }

                if (!is_readable($sourcePath)) {
                    $this->logger->warn(
                        'Media #{media_id} ({index}/{total}): the original file "{filename}" is not readable.', // @translate
                        ['media_id' => $media->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess, 'filename' => $filename]
                    );
                    $row['exists'] = $notReadable;
                    $this->writeRow($row);
                    continue;
                }

                // Check the current files.
                foreach ($types as $type) {
                    $derivativePath = $basePath . '/' . $type . '/' . $filename;
                    if (file_exists($derivativePath) && !is_writeable($derivativePath)) {
                        $this->logger->warn(
                            'Media #{media_id} ({index}/{total}): derivative file "{filename}" is not writeable (type "{type}").', // @translate
                            ['media_id' => $media->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess, 'filename' => $filename, 'type' => $type]
                        );
                        $offset += self::SQL_LIMIT;
                        $row['exists'] = $notWriteable;
                        $this->writeRow($row);
                        continue 2;
                    }
                }

                $this->logger->info(
                    'Media #{media_id} ({index}/{total}): creating derivative files.', // @translate
                    ['media_id' => $media->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess]
                );

                $tempFile = $tempFileFactory->build();
                $tempFile->setTempPath($sourcePath);
                $tempFile->setStorageId($media->getStorageId());
                $tempFile->setSourceName($media->getSource());

                // Update other data: as long as the sha256 is good, the file is
                // good so there is not problem to update base data.
                $toFlush = false;

                $current = $media->getExtension();
                $new = $tempFile->getExtension();
                $toFlush = $toFlush || mb_strtolower((string) $current) !== mb_strtolower((string) $new);
                $media->setExtension($new);

                $current = $media->getMediaType();
                $new = $tempFile->getMediaType();
                $toFlush = $toFlush || $current !== $new;
                $media->setMediaType($new);

                $current = $media->getSize();
                $new = $tempFile->getSize();
                $toFlush = $toFlush || $current !== $new;
                $media->setSize($new);

                $hasOriginal = $media->hasOriginal();
                $toFlush = $toFlush || !$hasOriginal;
                $media->setHasOriginal(true);

                $hasThumbnails = $media->hasThumbnails();
                $result = $tempFile->storeThumbnails();
                $toFlush = $toFlush || $hasThumbnails !== $result;
                $media->setHasThumbnails($result);

                if ($toFlush) {
                    $this->entityManager->persist($media);
                }

                if ($result) {
                    ++$totalSucceed;
                    $this->logger->info(
                        'Media #{media_id} ({index}/{total}): derivative files created.', // @translate
                        ['media_id' => $media->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess]
                    );
                } else {
                    ++$totalFailed;
                    $this->logger->notice(
                        'Media #{media_id} ({index}/{total}): derivative files not created.', // @translate
                        ['media_id' => $media->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess]
                    );
                }

                $row['exists'] = $yes;
                $row['has_thumbnails'] = $hasThumbnails ? $yes : $no;
                $row['fixed'] = $result ? $yes : $failed;
                $this->writeRow($row);

                ++$totalProcessed;

                // Avoid memory issue.
                unset($media);
            }

            // Avoid memory issue.
            unset($medias);
            $this->entityManager->flush();
            $this->entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        $this->logger->info(
            'End of the creation of derivative files: {count}/{total} processed, {skipped} skipped, {succeed} succeed, {failed} failed.', // @translate
            ['count' => $totalProcessed, 'total' => $totalToProcess, 'skipped' => $totalToProcess - $totalProcessed, 'succeed' => $totalSucceed, 'failed' => $totalFailed]
        );

        $this->finalizeOutput();
    }

    /**
     * Create a doctrine expression for a range.
     *
     * @param string $column
     * @param array|string $ids
     * @return \Doctrine\Common\Collections\Expr\CompositeExpression[]
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
            if (strpos($range, '-') === false) {
                $conditions[] = $expr->eq($column, $range);
            } else {
                [$from, $to] = explode('-', $range);
                $from = strlen($from) ? (int) $from : null;
                $to = strlen($to) ? (int) $to : null;
                if ($from && $to) {
                    $conditions[] = $expr->andX($expr->gte($column, $from), $expr->lte($column, $to));
                } elseif ($from) {
                    $conditions[] = $expr->gte($column, $from);
                } elseif ($to) {
                    $conditions[] = $expr->lte($column, $to);
                }
            }
        }

        return $conditions;
    }

    /**
     * Clean a list of ranges of ids.
     *
     * @param string|array $ids
     */
    protected function rangeToArray($ids): array
    {
        $clean = function ($str): string {
            $str = preg_replace('/[^0-9-]/', ' ', (string) $str);
            $str = preg_replace('/\s+/', ' ', $str);
            return trim($str);
        };

        $ids = is_array($ids)
            ? array_map($clean, $ids)
            : explode(' ', $clean($ids));

        // Skip empty ranges, fake ranges  and ranges with multiple "-".
        return array_values(array_filter($ids, function ($v) {
            return !empty($v) && $v !== '-' && substr_count($v, '-') <= 1;
        }));
    }
}

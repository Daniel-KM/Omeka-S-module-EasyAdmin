<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Doctrine\Common\Collections\Criteria;

/**
 * Create thumbnails/derivatives for media files.
 *
 * This is a general-purpose job for creating derivatives. For bulk upload
 * specific handling, see FileDerivativeBulkUpload which can run as a "fake job"
 * (not persisted) during background imports.
 *
 * @see \EasyAdmin\Job\FileDerivativeBulkUpload
 */
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
        'extension' => 'Extension', // @translate
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

        $ingestersRebuild = $this->getArg('ingesters_rebuild');
        if (!is_array($ingestersRebuild) || !$ingestersRebuild) {
            $ingestersRebuild = ['has_original', 'iiif', 'iiif_presentation', 'oembed', 'url', 'youtube'];
        }
        $rebuildLocal = in_array('has_original', $ingestersRebuild, true);
        $ingestersNoOriginal = array_values(array_diff($ingestersRebuild, ['has_original']));

        // TODO Add a check job or merge with files check.
        // $fix = true;

        // Prepare the list of medias.

        // Entity are used, because it's not possible to update the value
        // "has_thumbnails" via api.
        $criteria = Criteria::create();
        $expr = $criteria->expr();

        // Always true expression to simplify process.
        $criteria->where($expr->gt('id', 0));

        $itemSets = array_values($this->getArg('item_sets') ?: []);
        if ($itemSets) {
            // TODO Include dql as a subquery.
            $dql = <<<'DQL'
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

        $skipExisting = $this->getArg('thumbnails_to_create') === 'missing';

        $withoutThumbnails = $this->getArg('original_without_thumbnails');
        if ($withoutThumbnails) {
            $criteria->andWhere($expr->eq('hasThumbnails', 0));
        }

        $totalResources = $this->api->search('media', ['limit' => 0])->getTotalResults();

        $criteria
            ->andWhere($expr->eq('hasOriginal', 1))
            ->orderBy(['id' => 'ASC'])
            ->setMaxResults(self::SQL_LIMIT);

        $collection = $rebuildLocal ? $this->mediaRepository->matching($criteria) : null;
        $totalToProcess = $collection ? $collection->count() : 0;

        if (empty($totalToProcess)) {
            if ($rebuildLocal) {
                $this->logger->info(
                    'No media to process for creation of derivative files (on a total of {total} medias). You may check your query.', // @translate
                    ['total' => $totalResources]
                );
            }
            if (!$ingestersNoOriginal) {
                return;
            }
        }

        if ($rebuildLocal && $totalToProcess) {
        $this->logger->info(
            'Processing creation of derivative files of {total_process} medias (on a total of {total} medias).', // @translate
            ['total_process' => $totalToProcess, 'total' => $totalResources]
        );

        // Do the process.

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate
        $skipped = $translator->translate('Skipped'); // @translate
        $notReadable = $translator->translate('Not readable'); // @translate
        $notWriteable = $translator->translate('Not writeable'); // @translate
        $failed = $translator->translate('Failed'); // @translate

        $offset = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalExisting = 0;
        $totalFailed = 0;
        while (true) {
            $criteria
                ->setFirstResult($offset);
            $medias = $this->mediaRepository->matching($criteria);
            if (!$medias->count()) {
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

                // Check if the current files are writeable.
                // If one of the thumbnails are missing, recreate all.
                // If all thumbnails exist and option is to create only missing, skip the media.
                $missingThumbnail = false;
                foreach ($types as $type) {
                    $derivativePath = $basePath . '/' . $type . '/' . $filename;
                    if (!file_exists($derivativePath)) {
                        $missingThumbnail = true;
                    } elseif (!is_writeable($derivativePath)) {
                        $this->logger->warn(
                            'Media #{media_id} ({index}/{total}): derivative file "{filename}" is not writeable (type "{type}").', // @translate
                            ['media_id' => $media->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess, 'filename' => $filename, 'type' => $type]
                        );
                        $row['exists'] = $notWriteable;
                        $this->writeRow($row);
                        continue 2;
                    }
                }

                // Skip if all thumbnails exist and we only want to create missing ones.
                if (!$missingThumbnail && $skipExisting) {
                    $row['exists'] = $yes;
                    $row['has_thumbnails'] = $yes;
                    $row['fixed'] = $skipped;
                    $this->writeRow($row);
                    ++$totalExisting;
                    continue;
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

            if ($totalProcessed % 100 === 0) {
                if ($skipExisting) {
                    $this->logger->info(
                        'Progress: {processed}/{total} media processed, {existing} existing, {succeed} succeed, {failed} failed so far.', // @translate
                        ['processed' => $totalProcessed, 'total' => $totalToProcess, 'existing' => $totalExisting, 'succeed' => $totalSucceed, 'failed' => $totalFailed]
                    );
                } else {
                    $this->logger->info(
                        'Progress: {processed}/{total} media processed, {succeed} succeed, {failed} failed so far.', // @translate
                        ['processed' => $totalProcessed, 'total' => $totalToProcess, 'succeed' => $totalSucceed, 'failed' => $totalFailed]
                    );
                }
            }

            // Avoid memory issue.
            unset($medias);
            $this->entityManager->flush();
            $this->entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        if ($skipExisting) {
            $this->logger->info(
                'End of the creation of derivative files: {count}/{total} processed, {skipped} skipped, {existing} existing,  {succeed} succeed, {failed} failed.', // @translate
                ['count' => $totalProcessed, 'total' => $totalToProcess, 'skipped' => $totalToProcess - $totalProcessed, 'existing' => $totalExisting, 'succeed' => $totalSucceed, 'failed' => $totalFailed]
            );
        } else {
            $this->logger->info(
                'End of the creation of derivative files: {count}/{total} processed, {skipped} skipped, {succeed} succeed, {failed} failed.', // @translate
                ['count' => $totalProcessed, 'total' => $totalToProcess, 'skipped' => $totalToProcess - $totalProcessed, 'succeed' => $totalSucceed, 'failed' => $totalFailed]
            );
        }
        }

        if ($ingestersNoOriginal) {
            $this->processMediaWithoutOriginal(
                $ingestersNoOriginal,
                $basePath,
                $types,
                $skipExisting,
                $itemSets,
                $mediaIds
            );
        }

        $entityTypes = $this->getArg('entity_types') ?: ['media'];
        if (in_array('digital_object', $entityTypes, true)) {
            $this->processDigitalObjects($basePath, $types, $skipExisting, $tempFileFactory);
            $this->processDigitalObjectsWithoutOriginal($basePath, $types, $skipExisting);
        }

        $this->finalizeOutput();
    }

    /**
     * Rebuild derivatives for DigitalObject entities (module DigitalObject).
     *
     * Mirrors the media loop but on the `digital_object` sub-table and its
     * dedicated repository. Skipped silently if the module/table is not
     * available.
     */
    protected function processDigitalObjects(
        string $basePath,
        array $types,
        bool $skipExisting,
        \Omeka\File\TempFileFactory $tempFileFactory
    ): void {
        $doClass = 'DigitalObject\\Entity\\DigitalObject';
        if (!class_exists('DigitalObject\Module', false)) {
            return;
        }

        $repository = $this->entityManager->getRepository($doClass);

        $criteria = Criteria::create();
        $expr = $criteria->expr();
        $criteria
            ->where($expr->gt('id', 0))
            ->andWhere($expr->eq('hasOriginal', 1))
            ->orderBy(['id' => 'ASC'])
            ->setMaxResults(self::SQL_LIMIT);

        $totalResources = $repository->count([]);
        $totalToProcess = $repository->matching($criteria)->count();

        if (!$totalToProcess) {
            $this->logger->info(
                'No digital object to process for creation of derivative files (on a total of {total}).', // @translate
                ['total' => $totalResources]
            );
            return;
        }

        $this->logger->info(
            'Processing creation of derivative files of {total_process} digital objects (on a total of {total}).', // @translate
            ['total_process' => $totalToProcess, 'total' => $totalResources]
        );

        $offset = 0;
        $succeed = 0;
        $failed = 0;
        $existing = 0;

        while (true) {
            $criteria->setFirstResult($offset);
            $items = $repository->matching($criteria);
            if (!$items->count()) {
                break;
            }

            foreach ($items as $key => $entity) {
                if ($this->shouldStop()) {
                    break 2;
                }

                $storageId = $entity->getStorageId();
                $extension = $entity->getExtension() ?: '';
                if (!$storageId) {
                    ++$failed;
                    continue;
                }

                $filename = $storageId . ($extension !== '' ? '.' . $extension : '');
                $sourcePath = $basePath . '/original/' . $filename;
                if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
                    $this->logger->warn(
                        'DigitalObject #{id}: original file "{filename}" missing or not readable.', // @translate
                        ['id' => $entity->getId(), 'filename' => $filename]
                    );
                    ++$failed;
                    continue;
                }

                if ($skipExisting && $entity->hasThumbnails()) {
                    $allExist = true;
                    foreach ($types as $type) {
                        if (!file_exists($basePath . '/' . $type . '/' . $storageId . '.jpg')) {
                            $allExist = false;
                            break;
                        }
                    }
                    if ($allExist) {
                        ++$existing;
                        continue;
                    }
                }

                $tempFile = $tempFileFactory->build();
                $tempFile->setTempPath($sourcePath);
                $tempFile->setStorageId($storageId);
                $tempFile->setSourceName($entity->getSource());

                $result = $tempFile->storeThumbnails();
                $entity->setHasThumbnails((bool) $result);
                $this->entityManager->persist($entity);

                if ($result) {
                    ++$succeed;
                    $this->logger->info(
                        'DigitalObject #{id} ({index}/{total}): derivative files created.', // @translate
                        ['id' => $entity->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess]
                    );
                } else {
                    ++$failed;
                    $this->logger->notice(
                        'DigitalObject #{id} ({index}/{total}): derivative files not created.', // @translate
                        ['id' => $entity->getId(), 'index' => $offset + $key + 1, 'total' => $totalToProcess]
                    );
                }
            }

            unset($items);
            $this->entityManager->flush();
            $this->entityManager->clear();
            $offset += self::SQL_LIMIT;
        }

        $this->logger->info(
            'End of derivative files creation for digital objects: {succeed} succeed, {existing} skipped, {failed} failed.', // @translate
            ['succeed' => $succeed, 'existing' => $existing, 'failed' => $failed]
        );
    }

    /**
     * Rebuild thumbnails for digital objects without an original file (IIIF
     * Image, IIIF Presentation), by downloading the remote thumbnail.
     *
     * Mirrors processMediaWithoutOriginal() but resolves the url from the
     * digital object data (raw info.json for IIIF Image, manifest thumbnail for
     * IIIF Presentation).
     */
    protected function processDigitalObjectsWithoutOriginal(
        string $basePath,
        array $types,
        bool $skipExisting
    ): void {
        $doClass = 'DigitalObject\\Entity\\DigitalObject';
        if (!class_exists('DigitalObject\Module', false)) {
            return;
        }

        $repository = $this->entityManager->getRepository($doClass);

        $criteria = Criteria::create();
        $expr = $criteria->expr();
        $criteria
            ->where($expr->eq('hasOriginal', 0))
            ->orderBy(['id' => 'ASC'])
            ->setMaxResults(self::SQL_LIMIT);

        $totalToProcess = $repository->matching($criteria)->count();
        if (!$totalToProcess) {
            $this->logger->info(
                'No digital object without original to process.' // @translate
            );
            return;
        }

        $this->logger->info(
            'Processing {total} digital objects without original (IIIF).', // @translate
            ['total' => $totalToProcess]
        );

        $downloader = $this->getServiceLocator()->get('Omeka\File\Downloader');

        $offset = 0;
        $succeed = 0;
        $failed = 0;
        $existing = 0;

        while (true) {
            $criteria->setFirstResult($offset);
            $items = $repository->matching($criteria);
            if (!$items->count()) {
                break;
            }

            foreach ($items as $entity) {
                if ($this->shouldStop()) {
                    break 2;
                }

                $storageId = $entity->getStorageId();
                if (!$storageId) {
                    ++$failed;
                    continue;
                }

                if ($skipExisting && $entity->hasThumbnails()) {
                    $allExist = true;
                    foreach ($types as $type) {
                        if (!file_exists($basePath . '/' . $type . '/' . $storageId . '.jpg')) {
                            $allExist = false;
                            break;
                        }
                    }
                    if ($allExist) {
                        ++$existing;
                        continue;
                    }
                }

                $thumbnailUrl = $this->getThumbnailUrlForDigitalObject($entity);
                if (!$thumbnailUrl) {
                    $this->logger->notice(
                        'DigitalObject #{id}: no thumbnail URL derivable.', // @translate
                        ['id' => $entity->getId()]
                    );
                    ++$failed;
                    continue;
                }

                $tempFile = $downloader->download($thumbnailUrl);
                if (!$tempFile) {
                    $this->logger->notice(
                        'DigitalObject #{id}: download failed for {url}.', // @translate
                        ['id' => $entity->getId(), 'url' => $thumbnailUrl]
                    );
                    ++$failed;
                    continue;
                }

                $tempFile->setStorageId($storageId);
                $result = $tempFile->storeThumbnails();
                @unlink($tempFile->getTempPath());

                $entity->setHasThumbnails((bool) $result);
                $this->entityManager->persist($entity);

                if ($result) {
                    ++$succeed;
                    $this->logger->info(
                        'DigitalObject #{id}: thumbnails rebuilt.', // @translate
                        ['id' => $entity->getId()]
                    );
                } else {
                    ++$failed;
                }
            }

            unset($items);
            $this->entityManager->flush();
            $this->entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        $this->logger->info(
            'End rebuild for digital objects without original: {succeed} succeed, {existing} skipped, {failed} failed.', // @translate
            ['succeed' => $succeed, 'existing' => $existing, 'failed' => $failed]
        );
    }

    /**
     * Derive the thumbnail url from a digital object stored data.
     *
     * IIIF Presentation stores the manifest thumbnail url directly; IIIF Image
     * stores its info.json raw, so the largest full-region image is computed
     * per API version.
     *
     * @see \DigitalObject\Api\Representation\DigitalObjectRepresentation
     */
    protected function getThumbnailUrlForDigitalObject($entity): ?string
    {
        $data = $entity->getData() ?: [];
        if (!is_array($data)) {
            return null;
        }

        // IIIF Presentation: the manifest thumbnail url is stored as data.
        if (($data['type'] ?? null) === 'iiif-presentation') {
            return !empty($data['thumbnail']) ? (string) $data['thumbnail'] : null;
        }

        // IIIF Image: data is the raw info.json.
        $context = $data['@context'] ?? '';
        $context = is_array($context) ? implode(' ', $context) : (string) $context;
        $isIiifImage = ($data['protocol'] ?? null) === 'http://iiif.io/api/image'
            || strpos($context, 'iiif.io/api/image') !== false;
        if (!$isIiifImage) {
            return null;
        }

        $serviceId = $data['id'] ?? $data['@id'] ?? null;
        if (!is_string($serviceId) || $serviceId === '') {
            return null;
        }
        $serviceId = rtrim($serviceId, '/');

        if (strpos($context, 'image/3') !== false) {
            return $serviceId . '/full/max/0/default.jpg';
        }
        return $serviceId . '/full/full/0/default.jpg';
    }

    /**
     * Rebuild thumbnails for media without original file (IIIF,
     * IiifPresentation, oEmbed, YouTube).
     */
    protected function processMediaWithoutOriginal(
        array $ingesters,
        string $basePath,
        array $types,
        bool $skipExisting,
        array $itemSets,
        $mediaIds
    ): void {
        $criteria = Criteria::create();
        $expr = $criteria->expr();
        $criteria
            ->where($expr->in('ingester', $ingesters))
            ->andWhere($expr->eq('hasOriginal', 0));

        if ($itemSets) {
            $dql = <<<'DQL'
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

        if ($mediaIds) {
            $range = $this->exprRange('id', $mediaIds);
            if ($range) {
                $criteria->andWhere($expr->orX(...$range));
            }
        }

        $criteria
            ->orderBy(['id' => 'ASC'])
            ->setMaxResults(self::SQL_LIMIT);

        $collection = $this->mediaRepository->matching($criteria);
        $totalToProcess = $collection->count();

        if (!$totalToProcess) {
            $this->logger->info(
                'No media without original to process for ingesters: {ingesters}.', // @translate
                ['ingesters' => implode(', ', $ingesters)]
            );
            return;
        }

        $this->logger->info(
            'Processing {total} media without original for ingesters: {ingesters}.', // @translate
            ['total' => $totalToProcess, 'ingesters' => implode(', ', $ingesters)]
        );

        $downloader = $this->getServiceLocator()->get('Omeka\File\Downloader');

        $offset = 0;
        $succeed = 0;
        $failed = 0;
        $existing = 0;

        while (true) {
            $criteria->setFirstResult($offset);
            $medias = $this->mediaRepository->matching($criteria);
            if (!$medias->count()) {
                break;
            }

            foreach ($medias as $media) {
                if ($this->shouldStop()) {
                    break 2;
                }

                $storageId = $media->getStorageId();
                if (!$storageId) {
                    ++$failed;
                    continue;
                }

                if ($skipExisting && $media->hasThumbnails()) {
                    $allExist = true;
                    foreach ($types as $type) {
                        if (!file_exists($basePath . '/' . $type . '/' . $storageId . '.jpg')) {
                            $allExist = false;
                            break;
                        }
                    }
                    if ($allExist) {
                        ++$existing;
                        continue;
                    }
                }

                $thumbnailUrl = $this->getThumbnailUrlForMedia($media);
                if (!$thumbnailUrl) {
                    $this->logger->notice(
                        'Media #{media_id} ({ingester}): no thumbnail URL derivable.', // @translate
                        ['media_id' => $media->getId(), 'ingester' => $media->getIngester()]
                    );
                    ++$failed;
                    continue;
                }

                $tempFile = $downloader->download($thumbnailUrl);
                if (!$tempFile) {
                    $this->logger->notice(
                        'Media #{media_id} ({ingester}): download failed for {url}.', // @translate
                        ['media_id' => $media->getId(), 'ingester' => $media->getIngester(), 'url' => $thumbnailUrl]
                    );
                    ++$failed;
                    continue;
                }

                $tempFile->setStorageId($storageId);
                $result = $tempFile->storeThumbnails();
                @unlink($tempFile->getTempPath());

                $media->setHasThumbnails((bool) $result);
                $this->entityManager->persist($media);

                if ($result) {
                    ++$succeed;
                    $this->logger->info(
                        'Media #{media_id} ({ingester}): thumbnails rebuilt.', // @translate
                        ['media_id' => $media->getId(), 'ingester' => $media->getIngester()]
                    );
                } else {
                    ++$failed;
                }
            }

            unset($medias);
            $this->entityManager->flush();
            $this->entityManager->clear();

            $offset += self::SQL_LIMIT;
        }

        $this->logger->info(
            'End rebuild for media without original: {succeed} succeed, {existing} skipped, {failed} failed.', // @translate
            ['succeed' => $succeed, 'existing' => $existing, 'failed' => $failed]
        );
    }

    /**
     * Derive the thumbnail URL for a media based on its ingester.
     */
    protected function getThumbnailUrlForMedia(\Omeka\Entity\Media $media): ?string
    {
        $ingester = $media->getIngester();
        $data = $media->getData() ?: [];
        switch ($ingester) {
            case 'youtube':
                $id = $data['id'] ?? null;
                return $id ? sprintf('https://img.youtube.com/vi/%s/0.jpg', rawurlencode($id)) : null;
            case 'iiif_presentation':
                $context = (string) ($data['@context'] ?? '');
                if (strpos($context, 'presentation/3') !== false) {
                    return $data['thumbnail'][0]['id'] ?? null;
                }
                return $data['thumbnail']['@id'] ?? ($data['thumbnail'][0]['id'] ?? null);
            case 'oembed':
                return $data['thumbnail_url'] ?? null;
            case 'url':
                return $media->getSource() ?: null;
            case 'iiif':
                return $this->getIiifImageUrlFromInfo($media->getSource());
            default:
                return null;
        }
    }

    /**
     * Fetch IIIF Image info.json and compute a full-image URL for each API
     * version.
     */
    protected function getIiifImageUrlFromInfo(?string $infoUrl): ?string
    {
        if (!$infoUrl) {
            return null;
        }
        try {
            /** @var \Laminas\Http\Client $client */
            $client = $this->getServiceLocator()->get('Omeka\HttpClient');
            $response = $client->resetParameters()->setUri($infoUrl)->send();
            if (!$response->isSuccess()) {
                return null;
            }
            $iiif = json_decode($response->getBody(), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($iiif)) {
            return null;
        }
        $context = $iiif['@context'] ?? '';
        if ($context === 'http://iiif.io/api/image/3/context.json') {
            $id = $iiif['id'] ?? null;
            return $id ? rtrim($id, '/') . '/full/max/0/default.jpg' : null;
        }
        if ($context === 'http://iiif.io/api/image/2/context.json') {
            $id = $iiif['@id'] ?? null;
            return $id ? rtrim($id, '/') . '/full/full/0/default.jpg' : null;
        }
        $id = $iiif['@id'] ?? null;
        return $id ? rtrim($id, '/') . '/full/full/0/native.jpg' : null;
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
        return array_values(array_filter($ids, fn ($v) => !empty($v) && $v !== '-' && substr_count($v, '-') <= 1));
    }
}

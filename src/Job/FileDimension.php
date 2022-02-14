<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileDimension extends AbstractCheckFile
{
    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'filename' => 'Filename', // @translate
        'media_type' => 'Media type', // @translate
        'type' => 'Type', // @translate
        'exists' => 'Exists', // @translate
        'dimensions' => 'Existing dimensions (w × h × d)', // @translate
        'new_dimensions' => 'New dimensions (w × h × d)', // @translate
        'fixed' => 'Fixed', // @translate
        'message' => 'Message', // @translate
    ];

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $services = $this->getServiceLocator();
        if (!$services->get('ControllerPluginManager')->has('mediaDimension')) {
            $this->logger->err(
                'This process requires module "{module}".', // @translate
                ['module' => 'Iiif Server']
            );
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->finalizeOutput();
            return;
        }

        $process = $this->getArg('process');

        $this->checkFilesDimensions($process === 'files_dimension_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    protected function checkFilesDimensions(bool $fix)
    {
        $types = array_merge(['original'], array_keys($this->config['thumbnails']['types']));

        // Entity are used, because it's not possible to get the value
        // "has_original" or "has_thumbnails" via api.
        $criteria = new \Doctrine\Common\Collections\Criteria();
        $expr = $criteria->expr();

        // TODO Manage creation of thumbnails for media without original (youtube…).
        // Check only media with an original file.
        $criteria
            ->where($expr->andX(
                $expr->orX(
                    $expr->eq('hasOriginal', 1),
                    $expr->eq('hasThumbnails', 1)
                ),
                $expr->orX(
                    $expr->startsWith('mediaType', 'image/'),
                    $expr->startsWith('mediaType', 'audio/'),
                    $expr->startsWith('mediaType', 'video/')
                )
            ))
            ->orderBy(['id' => 'ASC'])
            ->setFirstResult(null)
            ->setMaxResults(self::SQL_LIMIT);

        $collection = $this->mediaRepository->matching($criteria);
        $totalToProcess = $collection->count();

        if (empty($totalToProcess)) {
            $this->logger->notice(
                'No image, audio or video to process.' // @translate
            );
            return true;
        }

        $this->logger->notice(
            'Checking dimensions of a {total} of image/audio/video media with original or thumbnails.', // @translate
            ['total' => $totalToProcess]
        );

        // Do the process.

        $baseCriteria = $criteria;

        $services = $this->getServiceLocator();
        /** @var \IiifServer\Mvc\Controller\Plugin\MediaDimension $mediaDimension */
        $mediaDimension = $services->get('ControllerPluginManager')->get('mediaDimension');

        $translator = $services->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        // $no = $translator->translate('No'); // @translate
        $missingFile = $translator->translate('Missing file'); // @translate
        $notReadable = $translator->translate('Not readable'); // @translate
        $emptyFile = $translator->translate('Empty file'); // @translate
        // $failed = $translator->translate('Failed'); // @translate

        // Since the fixed medias are no more available in the database, the
        // loop should take care of them, so a check is done on it.
        $lastId = 0;

        $offset = 0;
        $totalProcessed = 0;
        $totalSucceed = 0;
        $totalFailed = 0;
        $totalFixed = 0;
        while (true) {
            $criteria = clone $baseCriteria;
            $criteria
                // Don't use offset, since last id is used, because some ids
                // may have been removed.
                ->andWhere($expr->gt('id', $lastId));
            $medias = $this->mediaRepository->matching($criteria);
            if (!$medias->count() || $offset >= $totalToProcess || $totalProcessed >= $totalToProcess) {
                break;
            }

            if ($this->shouldStop()) {
                $this->logger->notice(
                    'Job stopped: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed, {total_fixed} fixed.', // @translate
                    [
                        'processed' => $totalProcessed,
                        'total' => $totalToProcess,
                        'total_succeed' => $totalSucceed,
                        'total_failed' => $totalFailed,
                        'total_fixed' => $totalFixed,
                    ]
                );
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return false;
            }

            if ($totalProcessed) {
                $this->logger->info(
                    '{processed}/{total} media processed.', // @translate
                    ['processed' => $totalProcessed, 'total' => $totalToProcess]
                );
            }

            /** @var \Omeka\Entity\Media $media */
            foreach ($medias as $media) {
                $lastId = $media->getId();
                $item = $media->getItem();
                $itemId = $item->getId();
                $mediaType = (string) $media->getMediaType();
                $mediaData = $media->getData() ?? [];
                $newMediaData = $mediaData;
                $newMediaData['dimensions'] = [];
                $hasFailed = false;
                foreach ($types as $type) {
                    $isOriginal = $type === 'original';
                    // No thumbnail for audio.
                    $isAudio = substr($mediaType, 0, 6) === 'audio/';
                    if ($isAudio && !$isOriginal) {
                        continue;
                    }
                    $filename = $isOriginal
                        ? $media->getFilename()
                        : ($media->getStorageId() . '.jpg');
                    $row = [
                        'item' => $itemId,
                        'media' => $media->getId(),
                        'filename' => $filename,
                        'media_type' => $mediaType,
                        'type' => $type,
                        'exists' => '',
                        'dimensions' => '',
                        'new_dimensions' => '',
                        'fixed' => '',
                        'message' => '',
                    ];

                    $currentDimensions = $mediaData['dimensions'][$type] ?? null;
                    if ($currentDimensions) {
                        $row['dimensions'] = implode(' × ', $currentDimensions);
                    }

                    $dirpath = $this->basePath . '/' . $type;
                    $filepath = $dirpath . '/' . $filename;
                    if (!file_exists($filepath) || !is_file($filepath)) {
                        $row['exists'] = $missingFile;
                        $this->writeRow($row);
                        $hasFailed = true;
                        continue;
                    }
                    if (!is_readable($filepath)) {
                        $row['exists'] = $notReadable;
                        $this->writeRow($row);
                        $hasFailed = true;
                        continue;
                    }
                    if (!filesize($filepath)) {
                        $row['exists'] = $emptyFile;
                        $this->writeRow($row);
                        $hasFailed = true;
                        continue;
                    }

                    $dimensions = $mediaDimension($media, $type, true);
                    $newMediaData['dimensions'][$type] = $dimensions;
                    $row['new_dimensions'] = implode(' × ', $dimensions);

                    if ($currentDimensions === $dimensions) {
                        // Nothing to do.
                    } elseif ($fix && $newMediaData !== $mediaData) {
                        $row['fixed'] = $yes;
                    }

                    $this->writeRow($row);
                }

                if ($hasFailed) {
                    ++$totalFailed;
                } elseif ($fix && $newMediaData !== $mediaData) {
                    $media->setData($newMediaData);
                    $this->entityManager->persist($media);
                    ++$totalFixed;
                } else {
                    ++$totalSucceed;
                }

                ++$totalProcessed;

                // Avoid memory issue.
                unset($media);
            }

            // Avoid memory issue.
            $this->entityManager->flush();
            $this->entityManager->clear();
            unset($medias);

            $offset += self::SQL_LIMIT;
        }

        if ($fix) {
            $this->logger->notice(
                'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed, {total_fixed} fixed.', // @translate
                [
                    'processed' => $totalProcessed,
                    'total' => $totalToProcess,
                    'total_succeed' => $totalSucceed,
                    'total_failed' => $totalFailed,
                    'total_fixed' => $totalFixed,
                ]
            );
        } else {
            $this->logger->notice(
                'End of process: {processed}/{total} processed, {total_succeed} succeed, {total_failed} failed.', // @translate
                [
                    'processed' => $totalProcessed,
                    'total' => $totalToProcess,
                    'total_succeed' => $totalSucceed,
                    'total_failed' => $totalFailed,
                ]
            );
        }

        return true;
    }
}

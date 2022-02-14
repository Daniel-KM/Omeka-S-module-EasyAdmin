<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileMediaNoOriginal extends AbstractCheckFile
{
    protected $columns = [
        'item' => 'Item', // @translate
        'media' => 'Media', // @translate
        'renderer' => 'Renderer', // @translate
        'has_original' => 'Has original', // @translate
        'fixed' => 'Fixed', // @translate
    ];

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->checkMediaNoOriginal($process === 'files_media_no_original_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    /**
     * Check media that are rendered as file have value "has_original" set.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkMediaNoOriginal($fix = false)
    {
        // Some files (url) may have no original, but thumbnails.
        $sql = 'SELECT COUNT(`id`) FROM `media` WHERE `renderer` = "file" AND `has_original` != 1 AND `has_thumbnails` != 1';
        $totalToProcess = $this->connection->executeQuery($sql)->fetchOne();

        $this->logger->notice(
            'There are {count} media rendered as "file" without "has original" and "has thumbnails" set. Check if no creation or import is running before fixing it.', // @translate
            ['count' => $totalToProcess]
        );

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate

        $baseCriteria = new \Doctrine\Common\Collections\Criteria();
        $expr = $baseCriteria->expr();
        $baseCriteria
            ->where($expr->eq('renderer', 'file'))
            ->andWhere($expr->eq('hasOriginal', 0))
            ->andWhere($expr->eq('hasThumbnails', 0))
            ->orderBy(['id' => 'ASC'])
            ->setFirstResult(null)
            ->setMaxResults(self::SQL_LIMIT);

        // Since the fixed medias are no more available in the database, the
        // loop should take care of them, so a check is done on it.
        $lastId = 0;

        // Loop all media without original files.
        $offset = 0;
        $totalProcessed = 0;
        $totalFixed = 0;
        while (true) {
            $criteria = clone $baseCriteria;
            $criteria
                // Don't use offset, since last id is used.
                // ->setFirstResult($offset)
                ->andWhere($expr->gt('id', $lastId));
            $medias = $this->mediaRepository->matching($criteria);
            if (!$medias->count() || $offset >= $medias->count()) {
                break;
            }

            if ($this->shouldStop()) {
                if ($fix) {
                    $this->logger->notice(
                        'Job stopped: {processed}/{total} processed, {total_fixed} fixed.', // @translate
                        ['processed' => $totalProcessed, 'total' => $totalToProcess, 'total_fixed' => $totalFixed]
                    );
                } else {
                    $this->logger->notice(
                        'Job stopped: {processed}/{total} processed.', // @translate
                        ['processed' => $totalProcessed, 'total' => $totalToProcess, ]
                    );
                }
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
                $row = [
                    'item' => $media->getItem()->getId(),
                    'media' => $media->getId(),
                    'renderer' => 'file',
                    'has_original' => '0',
                ];
                if ($fix) {
                    $this->entityManager->remove($media);
                    $row['fixed'] = $yes;
                    ++$totalFixed;
                } else {
                    $row['fixed'] = $no;
                }
                $this->writeRow($row);
                ++$totalProcessed;
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            unset($medias);

            $offset += self::SQL_LIMIT;
        }

        if ($fix) {
            $this->logger->notice(
                'End of process: {processed}/{total} processed, {total_fixed} fixed.', // @translate
                ['processed' => $totalProcessed, 'total' => $totalToProcess, 'total_fixed' => $totalFixed]
            );
        } else {
            $this->logger->notice(
                'End of process: {processed}/{total} processed.', // @translate
                ['processed' => $totalProcessed, 'total' => $totalToProcess, ]
            );
        }

        return true;
    }
}

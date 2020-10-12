<?php declare(strict_types=1);
namespace BulkCheck\Job;

class FileMediaNoOriginal extends AbstractCheckFile
{
    public function perform(): void
    {
        parent::perform();

        $this->initializeOutput();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->checkMediaNoOriginal($process === 'files_media_no_original_fix');

        $this->messageResultFile();

        $this->finalizeOutput();

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function initializeOutput()
    {
        parent::initializeOutput();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return $this;
        }

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $row = [
            $translator->translate('Item'), // @translate
            $translator->translate('Media'), // @translate
            $translator->translate('Renderer'), // @translate
            $translator->translate('Has original'), // @translate
            $translator->translate('Fixed'), // @translate
        ];
        $this->writeRow($row);

        return $this;
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
        $totalToProcess = $this->connection->query($sql)->fetchColumn();

        $this->logger->notice(
            'There are {count} media rendered as "file" without "has original" and "has thumbnails" set. Check if no creation or import is running before fixing it.', // @translate
            ['count' => $totalToProcess]
        );

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate

        $criteria = [];
        $criteria['renderer'] = 'file';
        $criteria['hasOriginal'] = 0;
        $criteria['hasThumbnails'] = 0;

        // Loop all media without original files.
        $offset = 0;
        $totalProcessed = 0;
        while (true) {
            /** @var \Omeka\Entity\Media[] $medias */
            $medias = $this->mediaRepository->findBy($criteria, ['id' => 'ASC'], self::SQL_LIMIT, $offset);
            if (!count($medias)) {
                break;
            }

            if ($offset) {
                $this->logger->info(
                    '{processed}/{total} media processed.', // @translate
                    ['processed' => $offset, 'total' => $totalToProcess]
                );

                if ($this->shouldStop()) {
                    $this->logger->warn(
                        'The job was stopped.' // @translate
                    );
                    return false;
                }
            }

            foreach ($medias as $media) {
                $row = [
                    'item' => $media->getItem()->getId(),
                    'media' => $media->getId(),
                    'renderer' => 'file',
                    'has_original' => '0',
                ];
                if ($fix) {
                    $row['fixed'] = $yes;
                    $this->entityManager->remove($media);
                } else {
                    $row['fixed'] = $no;
                }
                $this->writeRow($row);
                ++$totalProcessed;
            }

            $this->entityManager->flush();
            $this->mediaRepository->clear();
            unset($medias);

            $offset += self::SQL_LIMIT;
        }

        $this->logger->notice(
            'End of process: {processed}/{total} processed.', // @translate
            [
                'processed' => $totalProcessed,
                'total' => $totalToProcess,
            ]
        );

        return true;
    }
}

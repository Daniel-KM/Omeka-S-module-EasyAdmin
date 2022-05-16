<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Log\Stdlib\PsrMessage;

class ItemNoValue extends AbstractCheckFile
{
    protected $columns = [
        'item' => 'Item', // @translate
        'created' => 'Created', // @translate
        'media_count' => 'Media count', // @translate
        'fixed' => 'Fixed', // @translate
        'note' => 'Note', // @translate
    ];

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $this->checkItemNoValue($process === 'item_no_value_fix');

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );

        $this->finalizeOutput();
    }

    /**
     * Check if items have no value.
     *
     * @param bool $fix
     * @return bool
     */
    protected function checkItemNoValue($fix = false)
    {
        $sql = <<<SQL
SELECT item.id AS i, resource.created AS c, COUNT(media.id) AS m
FROM item
LEFT JOIN resource ON resource.id = item.id
LEFT JOIN value ON value.resource_id = item.id
LEFT JOIN media ON media.item_id = item.id
WHERE value.id IS NULL
GROUP BY item.id
ORDER BY item.id ASC;
SQL;
        // They are generally few.
        $items = $this->connection->executeQuery($sql)->fetchAllAssociative();

        $this->logger->notice(
            'There are {count} items without value.', // @translate
            ['count' => count($items)]
        );
        if (!count($items)) {
            return;
        }

        if (!$fix) {
            foreach ($items as $data) {
                $row = $data;
                $row['fixed'] = '';
                $row['note'] = '';
                $this->writeRow($row);
            }
            return;
        }

        // Creation of a folder is required for module ArchiveRepertory
        // or some other ones. Nevertheless, the check is not done for
        // performance reasons (and generally useless).
        $originalPath = $this->basePath . '/original';
        $movePath = $this->basePath . '/check/original';
        $this->createDir(dirname($movePath));

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $yes = $translator->translate('Yes'); // @translate
        $no = $translator->translate('No'); // @translate

        foreach ($items as $data) {
            $row = $data;
            $row['fixed'] = $yes;
            $row['note'] = '';
            if (empty($data['m'])) {
                $this->writeRow($row);
                continue;
            }

            /** @var \Omeka\Entity\Media[] $medias */
            $medias = $this->mediaRepository->findBy(['item' => $data['i']]);
            foreach ($medias as $media) {
                if ($media->hasOriginal()) {
                    $filename = $media->getFilename();
                    if (!file_exists($originalPath . '/' . $filename)) {
                        $message = new PsrMessage(
                            'Media #{media_id}: Original file "{filename}" was missing.', // @translate
                            ['media_id' => $media->getId(), 'filename' => $filename]
                        );
                        $row['note'] .= $message ."\n";
                        $this->logger->err($message->getMessage(), $message->getContext());
                    } else {
                        // Creation of a folder is required for module ArchiveRepertory
                        // or some other ones.
                        $dirname = dirname($movePath . '/' . $filename);
                        if ($dirname !== $movePath) {
                            if (!$this->createDir($dirname)) {
                                $row['fixed'] = $no;
                                $this->writeRow($row);
                                $this->logger->err(
                                    'Unable to prepare directory "{path}". Check rights.', // @translate
                                    ['path' => '/files/check/original/' . $filename]
                                );
                                $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                                return;
                            }
                        }
                        $result = @copy($originalPath . '/' . $filename, $movePath . '/' . $filename);
                        if ($result) {
                            $message = new PsrMessage(
                                'Media #{media_id}: Original file "{filename}" was moved.', // @translate
                                ['media_id' => $media->getId(), 'filename' => $filename]
                            );
                            $row['note'] .= $message ."\n";
                            $this->logger->notice($message->getMessage(), $message->getContext());
                        } else {
                            $message = new PsrMessage(
                                'Media #{media_id}: Original file "{filename}" cannot be moved.', // @translate
                                ['media_id' => $media->getId(), 'filename' => $filename]
                            );
                            $row['note'] .= $message ."\n";
                            $this->logger->err($message->getMessage(), $message->getContext());
                            $row['fixed'] = $no;
                            $this->writeRow($row);
                            return;
                        }
                    }
                }
            }
            $row['note'] = str_replace("\n", ' | ', trim($row['note']));
            $this->writeRow($row);

            $this->entityManager->clear();
        }

        $this->api->batchDelete('items', array_column($items, 'i'));
    }
}

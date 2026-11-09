<?php declare(strict_types=1);

namespace EasyAdminTest\Job;

use EasyAdmin\Job\FileDerivative;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the loop of the job FileDerivative.
 */
class FileDerivativeTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    /**
     * More than one batch of the job.
     */
    const TOTAL = FileDerivative::SQL_LIMIT + 5;

    /**
     * @var string[]
     */
    protected $files = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->logout();
        parent::tearDown();
    }

    /**
     * Each media is processed once and the job ends, even when the processed
     * media leave the filter (missing thumbnails) and the batches are full.
     *
     * Version 3.4.42 skipped media and looped forever: the count of a lazy
     * collection ignores the limit, so it never reaches zero.
     */
    public function testEachMediaIsProcessedOnceAndTheJobEnds(): void
    {
        $mediaIds = $this->createMediaWithoutThumbnails(self::TOTAL);

        $services = $this->getApplicationServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        $writer = new \Laminas\Log\Writer\Mock();
        $services->get('Omeka\Logger')->addWriter($writer);

        $job = new \Omeka\Entity\Job();
        $job->setClass(FileDerivative::class);
        $job->setArgs([
            'process' => 'files_derivative',
            'original_without_thumbnails' => true,
            'media_ids' => min($mediaIds) . '-' . max($mediaIds),
        ]);
        $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
        $entityManager->persist($job);
        $entityManager->flush();

        // A regression would loop forever: fail instead of hanging the suite.
        set_time_limit(120);
        try {
            $instance = new FileDerivative($job, $services);
            $instance->perform();
        } finally {
            set_time_limit(0);
            $filepath = (new \ReflectionProperty($instance, 'filepath'));
            $filepath->setAccessible(true);
            if ($filepath->getValue($instance)) {
                $this->files[] = $filepath->getValue($instance);
            }
            $entityManager = $services->get('Omeka\EntityManager');
            $entityManager->remove($entityManager->merge($job));
            $entityManager->flush();
        }

        $processed = [];
        $ended = false;
        foreach ($writer->events as $event) {
            $message = (string) $event['message'];
            if (strpos($message, 'creating derivative files.') !== false) {
                $processed[] = $event['extra']['media_id'];
            } elseif (strpos($message, 'End of the creation of derivative files') === 0) {
                $ended = true;
            }
        }

        $this->assertTrue($ended);
        sort($processed);
        $this->assertSame($mediaIds, $processed);
    }

    /**
     * Create media with an original jpeg file and without thumbnails.
     *
     * @return int[]
     */
    protected function createMediaWithoutThumbnails(int $total): array
    {
        $entityManager = $this->getEntityManager();
        $basePath = $this->getBasePath();
        $item = $entityManager->find(\Omeka\Entity\Item::class, $this->createItem()->id());

        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        $medias = [];
        for ($i = 0; $i < $total; $i++) {
            $storageId = 'easyadmin_test_' . bin2hex(random_bytes(8));
            $file = $basePath . '/original/' . $storageId . '.jpg';
            file_put_contents($file, $jpeg);
            $this->files[] = $file;
            $media = new \Omeka\Entity\Media();
            $media->setItem($item);
            $media->setOwner($item->getOwner());
            $media->setIngester('upload');
            $media->setRenderer('file');
            $media->setStorageId($storageId);
            $media->setExtension('jpg');
            $media->setMediaType('image/jpeg');
            $media->setSource($storageId . '.jpg');
            $media->setHasOriginal(true);
            $media->setHasThumbnails(false);
            $media->setIsPublic(true);
            $media->setCreated(new \DateTime('now'));
            $entityManager->persist($media);
            $medias[] = $media;
        }
        $entityManager->flush();

        foreach (array_keys($this->getService('Config')['thumbnails']['types']) as $type) {
            foreach ($medias as $media) {
                $this->files[] = $basePath . '/' . $type . '/' . $media->getStorageId() . '.jpg';
            }
        }

        $ids = array_map(fn ($media) => $media->getId(), $medias);
        sort($ids);
        return $ids;
    }
}

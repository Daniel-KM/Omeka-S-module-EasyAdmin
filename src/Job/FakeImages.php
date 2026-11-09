<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use EasyAdmin\File\Store\LocalFakeImages;
use Omeka\Job\AbstractJob;

/**
 * Download images of Wikimedia Commons matching a keyword into "files/".
 *
 * The images are used as fake images on a copy of a site without its files.
 * The search is limited to the "Quality images", reviewed by the community,
 * so the images are not random files that may be shocking. The images are
 * stored locally, so the visitors do not request the servers of Wikimedia.
 */
class FakeImages extends AbstractJob
{
    /**
     * Sub-directory of "files/".
     */
    const DIRECTORY = 'fake';

    const SETTING = 'easyadmin_local_store_wikimedia_images';

    /**
     * Number of images: the downloads from Wikimedia may be slow.
     */
    const MAX_IMAGES = 12;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $keyword = trim((string) $this->getArg('keyword', ''));
        $subDir = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($keyword)), '-') ?: 'quality-images';
        $dir = $basePath . '/' . self::DIRECTORY . '/' . $subDir;
        if (!$services->get('Common\DirectoryManager')->checkDestinationDir($dir)) {
            $settings->set(self::SETTING, ['keyword' => $keyword, 'failed' => time()]);
            return;
        }

        $context = stream_context_create(['http' => [
            'timeout' => 20,
            'header' => "User-Agent: Omeka-S-module-EasyAdmin (fake images for a test copy)\r\n",
        ]]);

        $query = http_build_query([
            'action' => 'query',
            'format' => 'json',
            'generator' => 'search',
            'gsrsearch' => trim($keyword . ' incategory:"Quality images" filetype:bitmap'),
            'gsrnamespace' => 6,
            'gsrlimit' => 30,
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime',
            'iiurlwidth' => LocalFakeImages::WIDTHS['large'],
        ]);
        $json = @file_get_contents('https://commons.wikimedia.org/w/api.php?' . $query, false, $context);
        $data = $json ? json_decode($json, true) : null;

        $files = [];
        foreach ($data['query']['pages'] ?? [] as $page) {
            if ($this->shouldStop() || count($files) >= self::MAX_IMAGES) {
                break;
            }
            $info = $page['imageinfo'][0] ?? [];
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$info['mime'] ?? ''] ?? null;
            if (!$extension || empty($info['thumburl'])) {
                continue;
            }
            // Remove the tracking query.
            $content = @file_get_contents(strtok($info['thumburl'], '?'), false, $context);
            if (!$content) {
                continue;
            }
            $filename = sprintf('%02d.%s', count($files) + 1, $extension);
            if (file_put_contents($dir . '/' . $filename, $content)) {
                $files[] = $subDir . '/' . $filename;
            }
        }

        if (!$files) {
            $settings->set(self::SETTING, ['keyword' => $keyword, 'failed' => time()]);
            $logger->err(
                'No image of Wikimedia Commons could be downloaded for the keyword "{keyword}".', // @translate
                ['keyword' => $keyword]
            );
            return;
        }

        $settings->set(self::SETTING, ['keyword' => $keyword, 'files' => $files]);
        $logger->notice(
            '{count} images of Wikimedia Commons were downloaded for the keyword "{keyword}" into "{dir}".', // @translate
            ['count' => count($files), 'keyword' => $keyword, 'dir' => 'files/' . self::DIRECTORY . '/' . $subDir]
        );
    }
}

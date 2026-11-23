<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Common\Job\Exception\RuntimeException;

class FileDerivativeFileSystem extends AbstractCheck
{
    /**
     * @var \Omeka\Stdlib\Cli
     */
    protected $cli;

    public function perform(): void
    {
        parent::perform();

        $services = $this->getServiceLocator();
        $this->cli = $services->get('Omeka\Cli');

        // Check vips or convert first.
        $hasCliVips = (bool) $this->cli->getCommandPath('vips');
        $hasCliConvert = (bool) $this->cli->getCommandPath('convert');
        if (!$hasCliVips && !$hasCliConvert) {
            throw new RuntimeException(
                'The command line tools "vips" (recommended) or "convert" (image magick) are required to run this job.' // @translate
            );
        }

        // Ensure php CLI exists.
        $phpPath = $this->cli->getCommandPath('php');
        if (!$phpPath) {
            throw new RuntimeException(
                'The command line tool "php" is required to run this job.' // @translate
            );
        }

        // Include center option from config.
        $cropMode = $this->config['thumbnails']['types']['square']['options']['vips_gravity'] ?? 'centre';
        $basePath = $this->config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $skipExisting = $this->getArg('thumbnails_to_create') === 'missing';

        $logDir = $basePath . '/result/';
        if (!$this->checkDestinationDir($logDir)) {
            throw new RuntimeException(
                'The directory "{path}" is not writeable.', // @translate
                ['path' => $logDir]
            );
        }

        // Run the process via the script in data/scripts.
        $scriptPath = dirname(__DIR__, 2) . '/data/scripts/thumbnailize.php';
        $modeArg = $skipExisting ? '--missing' : '--all';

        $date = (new \DateTime())->format('Ymd-His');
        $logFile = sprintf('%s/result/thumbnailize-%s-%s.log', $basePath, $date, $this->job->getId());

        $command = sprintf(
            '%s %s %s --main-dir %s --log-file %s --crop-mode %s --no-progress',
            escapeshellcmd($phpPath),
            escapeshellarg($scriptPath),
            $modeArg,
            escapeshellarg($basePath),
            escapeshellarg($logFile),
            escapeshellarg($cropMode)
        );

        $result = $this->cli->execute($command);
        if ($result === false) {
            throw new RuntimeException(
                'Thumbnailization script failed.' // @translate
            );
        } elseif ($result) {
            throw new RuntimeException(
                'Thumbnailization script output: {output}', // @translate
                ['output' => $result]
            );
        }

        $this->logger->notice(
            'Thumbnailization completed.' // @translate
        );
    }
}

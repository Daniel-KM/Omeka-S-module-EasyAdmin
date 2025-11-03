<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class FileDerivativeFileSystem extends AbstractCheck
{
    /**
     * @var \Omeka\Stdlib\Cli
     */
    protected $cli;

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $services = $this->getServiceLocator();
        $this->cli = $services->get('Omeka\Cli');

        // Check vips or convert first.
        $hasCliVips = (bool) $this->cli->getCommandPath('vips');
        $hasCliConvert = (bool) $this->cli->getCommandPath('convert');
        if (!$hasCliVips && !$hasCliConvert) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The command line tools "vips" (recommended) or "convert" (image magick) are required to run this job.' // @translate
            );
            return;
        }

        // Ensure php CLI exists.
        $phpPath = $this->cli->getCommandPath('php') ?: 'php';
        if (!$phpPath) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The command line tool "php" is required to run this job.' // @translate
            );
            return;
        }

        // Include center option from config.
        $cropMode = $this->config['thumbnails']['types']['square']['options']['vips_gravity'] ?? 'centre';
        $basePath = $this->config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $skipExisting = $this->getArg('thumbnails_to_create') === 'missing';

        $logDir = $basePath . '/result/';
        if (!$this->checkDestinationDir($logDir)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
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
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Thumbnailization script failed.' // @translate
            );
            return;
        } elseif ($result) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->warn(
                'Thumbnailization script output: {output}', // @translate
                ['output' => $result ?? '']
            );
            return;
        }

        $this->logger->notice(
            'Thumbnailization completed.' // @translate
        );
    }
}

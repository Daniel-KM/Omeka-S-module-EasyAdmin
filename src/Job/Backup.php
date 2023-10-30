<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class Backup extends AbstractCheck
{
    use ZipTrait;

    /*
    protected $columns = [
        'filename' => 'Filename', // @translate
        'extension' => 'Extension', // @translate
        'size' => 'Size', // @translate
        'sha256' => 'SHA-256', // @translate
        'included' => 'Included', // @translate
    ];
    */

    protected $availableToZip = [
        'core',
        'modules',
        'themes',
        // 'files',
        'logs',
        'local_config',
        'database_ini',
        'htaccess',
        'htpasswd',
        'hidden',
        'zip',
    ];

    /**
     * @var string
     */
    protected $destination;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->cli = $services->get('Omeka\Cli');

        parent::perform();

        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        if (!$this->prepareZipProcessor()) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'To do a backup, the php extension "zip" or the command "zip" should be installed on the server.' // @translate
            );
            return;
        }

        $include = $this->getArg('include');
        if (!$include) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Nothing selected for backup.' // @translate
            );
            return;
        }

        $includeArg = $include;
        $include = array_intersect($this->availableToZip, $include);
        if (count($include) !== count($includeArg)) {
            $this->logger->warn(
                'Some files are not storable: {files}', // @translate
                ['files' => implode(', ', array_diff($includeArg, $include))]
            );
        }

        $process = $this->getArg('process');
        $compression = $this->getArg('compression');
        $compression = $compression === null ? -1 : (int) $compression;

        $filepath = $this->backup($include, [
            'compression' => $compression,
        ]);

        if (!$filepath) {
            return;
        }

        $backupDir = $this->basePath . '/backup';
        if (mb_strpos($filepath, $backupDir . '/') === 0) {
            // The path between store and filename is the prefix.
            $dir = pathinfo($filepath, PATHINFO_DIRNAME);
            $filename = pathinfo($filepath, PATHINFO_FILENAME);
            $extension = pathinfo($filepath, PATHINFO_EXTENSION);
            $storagePath = sprintf('%s/%s.%s', mb_substr($dir, mb_strlen($this->basePath) + 1), $filename, $extension);
            $store = $services->get('Omeka\File\Store');
            $fileUrl = $store->getUri($storagePath);
            $this->logger->notice(
                'The backup is available at {link} (size: {size} bytes).', // @translate
                [
                    'link' => sprintf('<a href="%1$s" download="%2$s">%2$s</a>', $fileUrl, basename($filename)),
                    'size' => number_format((int) filesize($filepath), 0, ',', ' '),
                ]
            );
        }

        $this->logger->notice(
            'Process "{process}" completed.', // @translate
            ['process' => $process]
        );
    }

    protected function backup(array $include, array $options): ?string
    {
        $destinationDir = $this->basePath . '/backup';
        if (!$this->checkDestinationDir($destinationDir)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return null;
        }

        $label = $this->getArg('process', '');
        $base = preg_replace('/[^A-Za-z0-9]/', '_', $label);
        $base = $base ? substr(preg_replace('/_+/', '_', $base), 0, 20) . '-' : '';
        $date = (new \DateTime())->format('Ymd-His');
        $extension = 'zip';

        $i = 0;
        do {
            $filename = sprintf('%s%s%s.%s', $base, $date, $i ? '-' . $i : '', $extension);
            $filePath = $destinationDir . '/' . $filename;
            if (!file_exists($filePath)) {
                break;
            }
        } while (++$i);

        $exclude = $this->includeToExclude($include);

        $result = $this->zip(OMEKA_PATH, $filePath, $exclude, $options['compression']);
        if ($result['error']) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return null;
        }

        if ($result['size']) {
            $this->logger->notice(
                'Backup successfully created: {total_dirs} dirs, {total_files} files, size: {total_size} bytes, compressed: {size} bytes ({ratio}%).', // @translate
                [
                    'total_dirs' => number_format((int) $result['total_dirs'], 0, ',', ' '),
                    'total_files' => number_format((int) $result['total_files'], 0, ',', ' '),
                    'total_size' => number_format((int) $result['total_size'], 0, ',', ' '),
                    'size' => number_format((int) $result['size'], 0, ',', ' '),
                    'ratio' => (int) ($result['size'] / $result['total_size'] * 100),
                ]
            );
        }

        return $filePath;
    }

    /**
     * Convert the list to include into a list to exclude from the source.
     */
    protected function includeToExclude(array $include): array
    {
        $exclude = [
            'build/',
            'node_modules/',
            'files/',
        ];

        if (!in_array('core', $include)) {
            $exclude = array_merge($exclude, [
                '.git/',
                '.github/',
                '.settings/',
                '.tx/',
                'application/',
                'build/',
                'config/',
                'files/',
                'logs/',
                'node_modules/',
                'vendor/',
                '.gitignore',
                '.htaccess',
                '.htaccess.dist',
                '.htpasswd',
                '.php-cs-fixer.dist.php',
                '.php_cs_module',
                '.project',
                'bootstrap.php',
                'cli-config.php',
                'composer.json',
                'composer.lock',
                'gulpfile.js',
                'index.php',
                'package.json',
                'package-lock.json',
                'LICENSE',
                'README.md',
            ]);
        }

        if (!in_array('modules', $include)) {
            $exclude[] ='modules/';
        }

        if (!in_array('themes', $include)) {
            $exclude[] = 'themes/';
        }

        if (!in_array('files', $include)) {
            $exclude[] ='files/';
        }

        if (!in_array('logs', $include)) {
            $exclude[] = 'logs/';
        }

        if (!in_array('local_config', $include)) {
            $exclude[] = 'config/local.config.php';
        }

        if (!in_array('database_ini', $include)) {
            $exclude[] = 'config/database.ini';
        }

        if (!in_array('htaccess', $include)) {
            $exclude[] = '.htaccess';
        }

        if (!in_array('htpasswd', $include)) {
            $exclude[] = '.htpasswd';
        }

        // Specific for now.
        if (!in_array('hidden', $include)) {
            $exclude[] = '.*';
        }

        if (!in_array('zip', $include)) {
            $exclude[] = '*.bzip';
            $exclude[] = '*.bz2';
            $exclude[] = '*.tar';
            $exclude[] = '*.gz';
            $exclude[] = '*.xz';
            $exclude[] = '*.zip';
        }

        return array_unique($exclude);
    }
}

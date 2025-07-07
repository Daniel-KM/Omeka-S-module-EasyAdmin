<?php declare(strict_types=1);

namespace EasyAdmin\Job;

class ThemeTemplate extends AbstractCheck
{
    protected $columns = [
        'module' => 'Module', // @translate
        'theme' => 'Theme', // @translate
        'filepath' => 'File', // @translate
        'fixed' => 'Fixed', // @translate
        'message_1' => 'Message 1', // @translate
        'message_2' => 'Message 2', // @translate
        'message_3' => 'Message 3', // @translate
    ];

    protected $columnsTranslatable = [
        'module',
        'fixed',
        'message_1',
        'message_2',
        'message_3',
    ];

    protected $totalProcessed = 0;
    protected $totalSucceed = 0;
    protected $totalFailed = 0;
    protected $totalFixed = 0;

    public function perform(): void
    {
        parent::perform();
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $process = $this->getArg('process');

        $supportedModules = [
            'Reference',
        ];
        $modules = $this->getArg('modules', []) ?: [];
        if (empty($modules)) {
            $this->logger->warn(
                'No modules defined for processing.' // @translate
            );
            return;
        }

        $modules = array_values(array_intersect($supportedModules, (array) $modules));
        if (!$modules) {
            $this->logger->warn(
                'No modules defined for processing.' // @translate
            );
            return;
        }

        $isFix = $process === 'theme_templates_fix';

        if ($isFix && !$this->getArg('backup_confirmed')) {
            $this->logger->err(
                'The process is not run because there is no confirmation that an external backup of themes and files exist.' // @translate
            );
            return;
        }

        $this->checkDirectory(OMEKA_PATH . '/themes/', $isFix);
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return;
        }

        $this->totalProcessed = 0;
        $this->totalSucceed = 0;
        $this->totalFailed = 0;
        $this->totalFixed = 0;

        $this->checkThemeTemplates($modules, $isFix);

        $this->logger->notice(
            'End of process: {processed} processed, {total_succeed} succeed, {total_failed} failed, {total_fixed} fixed.', // @translate
            [
                'processed' => $this->totalProcessed,
                'total_succeed' => $this->totalSucceed,
                'total_failed' => $this->totalFailed,
                'total_fixed' => $this->totalFixed,
            ]
        );

        $this->finalizeOutput();
    }

    protected function checkDirectory(string $directory, bool $isFix): self
    {
        $dir = rtrim($directory, '/');
        if (!$dir || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The directory "{path}" is not set or not readable.', // @translate
                ['path' => $dir]
            );
            return $this;
        }

        if (realpath($dir) !== $dir || strlen($dir) <= 1) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The directory "{path}" should be a real path ({realpath}).', // @translate
                ['path' => $dir, 'realpath' => realpath($dir)]
            );
            return $this;
        }

        return $this;
    }

    protected function checkThemeTemplates(array $modules, bool $isFix = false): self
    {
        $themes = glob(OMEKA_PATH . '/themes/*', GLOB_ONLYDIR);
        foreach ($themes as $themePath) {
            $theme = basename($themePath);
            foreach ($modules as $module) switch ($module) {
                case 'Reference':
                    // Move all block layouts to block templates except "reference" and "reference-tree".
                    $filepaths = glob(OMEKA_PATH . "/themes/$theme/view/common/block-layout/reference*");
                    $filepaths = array_filter($filepaths, fn ($v) => !in_array(basename($v), ['reference.phtml', 'reference-tree.phtml']));
                    $this->processThemeTemplates($module, $theme, $filepaths, $isFix);
                    break;
                default:
                    $this->logger->warnr(
                        'The module {module} is not managed.', // @translate
                        ['module' => $module]
                    );
            }
        }
        return $this;
    }

    protected function processThemeTemplates(string $module, string $theme, array $filepaths, bool $isFix): self
    {
        $start = mb_strlen(OMEKA_PATH . '/');

        $themeConfigUpdated = false;

        foreach ($filepaths as $filepath) {
            $filename = basename($filepath);
            $row = [
                'module' => $module,
                'theme' => $theme,
                'filepath' => mb_substr($filepath, $start),
                'fixed' => '',
                'message_1' => '',
                'message_2' => '',
                'message_3' => '',
            ];

            $source = $filepath;
            $destination = strtr($filepath, [
                OMEKA_PATH . "/themes/$theme/view/common/block-layout/"
                    => OMEKA_PATH . "/themes/$theme/view/common/block-template/",
            ]);
            $destinationDir = pathinfo($destination, PATHINFO_DIRNAME);

            $messages = [];

            // Check config theme.
            $themeConfigPath = dirname($filepath, 4) . '/config/theme.ini';
            if (!file_exists($themeConfigPath)) {
                $messages[] = 'The theme is invalid: no file config/theme.ini.'; // @translate
            } elseif (file_exists($themeConfigPath) && !is_readable($themeConfigPath)) {
                $messages[] = 'The theme file config/theme.ini is not readable.'; // @translate
            } elseif (file_exists($themeConfigPath) && !is_writeable($themeConfigPath)) {
                $messages[] = 'The theme file config/theme.ini is not writeable.'; // @translate
            }

            // Check destination directory.
            $destinationDirExists = file_exists($destinationDir);
            if ($destinationDirExists && !is_dir($destinationDir)) {
                $messages[] = 'The destination directory is invalid.'; // @translate
            } elseif ($destinationDirExists && is_dir($destinationDir) && !is_writeable($destinationDir)) {
                $messages[] = 'The destination directory is not writeable.'; // @translate
            } elseif (!$destinationDirExists && !is_writeable(pathinfo($destinationDir, PATHINFO_DIRNAME))) {
                $messages[] = 'The main destination directory is not writeable.'; // @translate
            } elseif ($isFix && !$destinationDirExists) {
                $check = mkdir($destinationDir, 0775, true);
                if (!$check) {
                    $messages[] = 'The destination directory cannot be created.'; // @translate
                }
            }

            // Check destination file.
            if ($source === $destination) {
                $messages[] = 'Invalid source file path.'; // @translate
            } elseif (file_exists($destination)) {
                $messages[] = 'The destination file exists.'; // @translate
            } elseif (!is_readable($source)) {
                $messages[] = 'The source is not readable.'; // @translate
            }

            // Fix.
            if ($isFix && !$messages) {
                $check = rename($source, $destination);
                if ($check) {
                    if (!$themeConfigUpdated) {
                        $string = sprintf($this->translator->translate('Automatically appended via module EasyAdmin on %s'), date('Y-m-d H:i:s'));
                        file_put_contents($themeConfigPath, "\n\n; $string\n\n", FILE_APPEND);
                        $themeConfigUpdated = true;
                    }
                    $basename = pathinfo($filename, PATHINFO_FILENAME);
                    $label = ucfirst(strtr($basename, ['-' => ' ', '_' => ' ']));
                    $string = "block_templates.reference.$basename = \"$label\"\n";
                    $check = file_put_contents($themeConfigPath, $string, FILE_APPEND);
                    if ($check) {
                        $row['fixed'] = 'Yes';
                    } else {
                        $messages[] = 'The file was moved, but the config/theme.ini cannot be updated.'; // @translate
                    }
                } else {
                    $messages[] = 'The source cannot be moved to destination.'; // @translate
                }
            }

            if ($messages) {
                $row['message_1'] = $messages[0];
                $row['message_2'] = $messages[1] ?? '';
                $row['message_3'] = $messages[2] ?? '';
                ++$this->totalFailed;
                $this->logger->warn(
                    'This process failed for template {template}: {message}', // @ŧranslate
                    ['template' => $row['filepath'], 'message' => implode(' ', $messages)]
                );
            } else {
                ++$this->totalSucceed;
                if (!$isFix) {
                    $this->logger->info(
                        'This template can be moved into directory block-template: {template}.', // @ŧranslate
                        ['template' => $row['filepath']]
                    );
                } else {
                    $this->logger->info(
                        'This template was moved into directory block-template: {template}.', // @ŧranslate
                        ['template' => $row['filepath']]
                    );
                }
            }

            $this->writeRow($row);

            ++$this->totalProcessed;
        }

        return $this;
    }
}

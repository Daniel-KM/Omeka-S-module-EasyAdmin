<?php declare(strict_types=1);

namespace EasyAdmin\Stdlib;

use Omeka\Stdlib\Cli;

/**
 * Diagnostics of the PHP-CLI used to run the background jobs: validity of the
 * configured path, alignment of its version and SAPI with the web server,
 * extensions parity, ability to spawn processes, dispatch strategy and
 * open_basedir perimeter. It returns structured data, so the same source is
 * used by the check task, the test action and the "system.info" listener.
 */
class JobCliChecker
{
    protected Cli $cli;

    protected array $config;

    protected ?string $dispatchStrategyClass;

    public function __construct(Cli $cli, array $config, ?string $dispatchStrategyClass = null)
    {
        $this->cli = $cli;
        $this->config = $config;
        $this->dispatchStrategyClass = $dispatchStrategyClass;
    }

    /**
     * Diagnostics of the PHP-CLI used by the jobs. The candidate scan (which
     * runs every usual binary) is skipped when $scanCandidates is false, for a
     * lighter summary (e.g. on the system information page).
     */
    public function check(bool $scanCandidates = true): array
    {
        $webMajorMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $configured = $this->config['cli']['phpcli_path'] ?? null;
        $auto = !$configured;
        $invalidReason = null;
        if ($configured) {
            $effective = $this->cli->validateCommand($configured);
            if ($effective === false) {
                $invalidReason = $this->invalidReason($configured);
            }
        } else {
            $effective = $this->cli->getCommandPath('php');
        }

        $exitCodes = $this->exitCodes();
        $strategy = $this->executeStrategy();
        $exitCodeLost = !empty($exitCodes[$strategy]['lost']);
        // Omeka cannot detect php when the exit code is lost, but it exists.
        $detected = $effective === false && $exitCodeLost ? $this->commandPath('php') : null;

        $result = [
            'configured' => $configured,
            'auto' => $auto,
            'effective' => $effective,
            'invalidReason' => $invalidReason,
            'web' => [
                'version' => PHP_VERSION,
                'majorMinor' => $webMajorMinor,
                'sapi' => php_sapi_name(),
            ],
            'canSpawn' => $this->canSpawn(),
            'executeStrategy' => $strategy,
            'exitCodes' => $exitCodes,
            'exitCodeLost' => $exitCodeLost,
            'recommendedStrategy' => $this->recommendedStrategy($exitCodes, $strategy),
            'detected' => $detected,
            'dispatchStrategy' => $this->dispatchStrategyClass
                ? substr((string) strrchr('\\' . $this->dispatchStrategyClass, '\\'), 1)
                : null,
            'openBasedir' => $this->openBasedirDirs(),
            'effectiveInfo' => null,
            'versionMatch' => null,
            'sapiIsCli' => null,
            'hasPdoMysql' => null,
            'missingExtensions' => [],
            'candidates' => [],
            'recommendedCli' => null,
            'recommendedCgi' => null,
        ];

        $binary = is_string($effective) && $effective !== '' ? $effective : $detected;
        if ($binary) {
            $info = $this->probe($binary);
            $result['effectiveInfo'] = $info;
            if ($info) {
                $result['versionMatch'] = $this->majorMinor($info['version']) === $webMajorMinor;
                $result['sapiIsCli'] = $this->isCli($info['sapi']);
                $exts = $this->extensions($binary);
                if ($exts !== null) {
                    $result['hasPdoMysql'] = in_array('pdo_mysql', $exts, true);
                    $result['missingExtensions'] = $this->missingExtensions($exts);
                }
            }
        }

        if (!$scanCandidates) {
            return $result;
        }

        $result['candidates'] = $this->scanCandidates($webMajorMinor, $result['openBasedir']);
        foreach ($result['candidates'] as $candidate) {
            if (($candidate['status'] ?? '') !== 'ok' || empty($candidate['ok'])) {
                continue;
            }
            if (!empty($candidate['cli']) && $result['recommendedCli'] === null) {
                $result['recommendedCli'] = $candidate['path'];
            }
            if (empty($candidate['cli']) && $result['recommendedCgi'] === null) {
                $result['recommendedCgi'] = $candidate['path'];
            }
        }

        return $result;
    }

    /**
     * Version and SAPI of a php binary, run in the web context, or null.
     */
    public function probe(string $path): ?array
    {
        $output = $this->run(escapeshellarg($path) . ' --version');
        if (!is_string($output) || !preg_match('/PHP (\d+\.\d+\.\d+)(?:\s*\(([^)]+)\))?/', $output, $m)) {
            return null;
        }
        return ['version' => $m[1], 'sapi' => $m[2] ?? ''];
    }

    /**
     * Loaded extensions of a php binary (lower case), or null.
     *
     * @return string[]|null
     */
    public function extensions(string $path): ?array
    {
        $output = $this->run(escapeshellarg($path) . ' -m');
        if (!is_string($output)) {
            return null;
        }
        $extensions = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '[') {
                $extensions[] = strtolower($line);
            }
        }
        return $extensions;
    }

    public function isCli(string $sapi): bool
    {
        return $sapi === '' || stripos($sapi, 'cli') !== false;
    }

    public function majorMinor(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /**
     * Whether the web can spawn a process (required to start any job).
     */
    public function canSpawn(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $procOpen = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
        $exec = function_exists('exec') && !in_array('exec', $disabled, true);
        return [
            'ok' => $procOpen || $exec,
            'strategy' => $this->config['cli']['execute_strategy'] ?? 'auto',
            'procOpen' => $procOpen,
            'exec' => $exec,
        ];
    }

    /**
     * Strategy used by the Omeka Cli, resolved like its factory.
     */
    public function executeStrategy(): string
    {
        $strategy = $this->config['cli']['execute_strategy'] ?? 'exec';
        if ($strategy !== 'auto') {
            return $strategy === 'proc_open' ? 'proc_open' : 'exec';
        }
        return $this->canSpawn()['procOpen'] ? 'proc_open' : 'exec';
    }

    /**
     * Run a trivial command with each strategy to check the exit code.
     *
     * Some shared hosts lose the exit code of the child process (status -1
     * although the command succeeded), so Omeka considers that every command
     * failed and cannot start any job.
     */
    public function exitCodes(): array
    {
        $spawn = $this->canSpawn();
        $result = [];
        foreach (['proc_open' => $spawn['procOpen'], 'exec' => $spawn['exec']] as $strategy => $available) {
            $run = $available ? $this->runRaw('printf ok', $strategy) : null;
            $result[$strategy] = [
                'available' => $available,
                'output' => $run['output'] ?? null,
                'code' => $run['code'] ?? null,
                'lost' => $run !== null && $run['output'] === 'ok' && $run['code'] !== 0,
            ];
        }
        return $result;
    }

    protected function recommendedStrategy(array $exitCodes, string $strategy): ?string
    {
        if (empty($exitCodes[$strategy]['lost'])) {
            return null;
        }
        foreach ($exitCodes as $other => $check) {
            if ($other !== $strategy && $check['available'] && $check['output'] === 'ok' && !$check['lost']) {
                return $other;
            }
        }
        return null;
    }

    /**
     * Output of a command, even when the host loses the exit code.
     */
    protected function run(string $command): ?string
    {
        $output = $this->cli->execute($command);
        if (is_string($output)) {
            return $output;
        }
        $run = $this->runRaw($command, $this->executeStrategy());
        return $run && $run['output'] !== '' ? $run['output'] : null;
    }

    protected function commandPath(string $command): ?string
    {
        return $this->run('command -v ' . escapeshellarg($command));
    }

    /**
     * Run a command without the Omeka Cli, that discards the output when the
     * exit code is not 0.
     *
     * @return array|null Trimmed output and exit code, or null if not run.
     */
    protected function runRaw(string $command, string $strategy): ?array
    {
        if ($strategy === 'proc_open') {
            $proc = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) {
                return null;
            }
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return ['output' => trim($output), 'code' => proc_close($proc)];
        }
        $lines = [];
        $code = null;
        if (@exec($command, $lines, $code) === false) {
            return null;
        }
        return ['output' => trim(implode("\n", $lines)), 'code' => $code];
    }

    /**
     * @return string[]
     */
    public function openBasedirDirs(): array
    {
        $openBasedir = (string) ini_get('open_basedir');
        return $openBasedir === '' ? [] : array_filter(array_map('trim', explode(PATH_SEPARATOR, $openBasedir)));
    }

    /**
     * @return string[] The usual php then php-cgi locations across distributions.
     */
    public function candidatePaths(string $webMajorMinor): array
    {
        $versionNoDot = str_replace('.', '', $webMajorMinor);
        $paths = [
            PHP_BINARY,
            (string) $this->commandPath('php'),
            (string) $this->commandPath('php' . $webMajorMinor),
            (string) $this->commandPath('php' . $versionNoDot),
            '/opt/php/' . $webMajorMinor . '/bin/php',
            '/usr/bin/php' . $webMajorMinor,
            '/usr/bin/php' . $versionNoDot,
            '/opt/remi/php' . $versionNoDot . '/root/usr/bin/php',
            '/opt/rh/php' . $versionNoDot . '/root/usr/bin/php',
            '/usr/local/php' . $versionNoDot . '/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
            // Cgi binaries: usable but not preferred.
            (string) $this->commandPath('php-cgi'),
            '/opt/php/' . $webMajorMinor . '/bin/php-cgi',
            '/usr/bin/php-cgi' . $webMajorMinor,
            '/usr/bin/php-cgi' . $versionNoDot,
            '/opt/remi/php' . $versionNoDot . '/root/usr/bin/php-cgi',
            '/usr/bin/php-cgi',
            '/usr/local/bin/php-cgi',
        ];
        $unique = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path !== '') {
                $unique[$path] = true;
            }
        }
        return array_keys($unique);
    }

    protected function scanCandidates(string $webMajorMinor, array $openBasedir): array
    {
        $candidates = [];
        $seen = [];
        foreach ($this->candidatePaths($webMajorMinor) as $path) {
            if (!$this->withinOpenBasedir($path, $openBasedir)) {
                $candidates[] = ['path' => $path, 'status' => 'outside_openbasedir'];
                continue;
            }
            $valid = $this->cli->validateCommand($path);
            // Several candidate paths may resolve to the same binary.
            if ($valid === false || isset($seen[$valid])) {
                continue;
            }
            $seen[$valid] = true;
            $info = $this->probe($valid);
            if ($info === null) {
                $candidates[] = ['path' => $valid, 'status' => 'not_executable'];
                continue;
            }
            $exts = $this->extensions($valid);
            $pdo = $exts === null ? null : in_array('pdo_mysql', $exts, true);
            $candidates[] = [
                'path' => $valid,
                'status' => 'ok',
                'version' => $info['version'],
                'sapi' => $info['sapi'],
                'pdo' => $pdo,
                'cli' => $this->isCli($info['sapi']),
                'ok' => $this->majorMinor($info['version']) === $webMajorMinor && $pdo !== false,
            ];
        }
        return $candidates;
    }

    /**
     * Web extensions (of this php-fpm process) missing in the given CLI list.
     *
     * @param string[] $cliExtensions Lower case.
     * @return string[]
     */
    protected function missingExtensions(array $cliExtensions): array
    {
        // Extensions that do not matter for a job process, so a difference does
        // not warrant a warning.
        $irrelevant = [
            'zend opcache', 'opcache', 'apcu', 'xdebug', 'xhprof', 'tideways', 'tideways_xhprof',
            // SAPI handlers reported by get_loaded_extensions(), not real jobs
            // extensions.
            'apache2handler', 'apache', 'apache2filter', 'cgi-fcgi', 'fpm-fcgi', 'litespeed', 'phpdbg',
        ];
        $web = array_diff(array_map('strtolower', get_loaded_extensions()), $irrelevant);
        $missing = array_values(array_diff($web, $cliExtensions));
        sort($missing);
        return $missing;
    }

    protected function withinOpenBasedir(string $path, array $dirs): bool
    {
        if (!$dirs) {
            return true;
        }
        foreach ($dirs as $dir) {
            $dir = rtrim($dir, '/');
            if ($dir !== '' && ($path === $dir || strpos($path, $dir . '/') === 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Precise reason why a configured command path is invalid.
     */
    protected function invalidReason(string $path): string
    {
        if (@realpath($path) === false) {
            return 'the path does not exist'; // @translate
        }
        if (!@is_file($path)) {
            return 'the path is not a file'; // @translate
        }
        if (!@is_executable($path)) {
            return 'the file is not executable'; // @translate
        }
        return 'the path is invalid'; // @translate
    }
}

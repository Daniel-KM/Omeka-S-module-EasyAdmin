<?php declare(strict_types=1);

namespace EasyAdmin\Stdlib;

/**
 * Audit the configuration of the web server for the files of Omeka.
 *
 * Some modules require a rewrite rule in the root ".htaccess" to protect the
 * files (Access) or to count the downloads (Analytics), and the sensitive
 * directories of "files/" are protected by a ".htaccess". These protections
 * work with Apache only and depend on the order of the rules.
 *
 * Each issue is an array with a level (error, warning, notice), a message
 * template and its parameters.
 */
class HtaccessAudit
{
    /**
     * Modules managing the files via a rewrite rule, with the route used.
     */
    const MODULE_ROUTES = [
        'Access' => 'access/files/',
        'Analytics' => 'download/files/',
    ];

    /**
     * @param string|null $htaccess Content of the root ".htaccess", or null
     *   when it is missing.
     * @param string[] $activeModules
     * @param string $serverSoftware Value of the server variable
     *   "SERVER_SOFTWARE", empty when unknown.
     */
    public function audit(?string $htaccess, array $activeModules, string $serverSoftware): array
    {
        $issues = [];
        $server = strtolower($serverSoftware);

        if (strpos($server, 'nginx') !== false) {
            $issues[] = [
                'error',
                'The web server is Nginx, that ignores the files ".htaccess": the sensitive directories of "files/" (backup, import, userdata…) are public and the rules of the modules are not applied. Configure them in the config of Nginx.', // @translate
                [],
            ];
            foreach (array_intersect(array_keys(self::MODULE_ROUTES), $activeModules) as $module) {
                $issues[] = [
                    'warning',
                    'The module {module} requires a rewrite of "files/" to "{route}" in the config of Nginx, for example: {example}', // @translate
                    ['module' => $module, 'route' => self::MODULE_ROUTES[$module], 'example' => 'location ~ ^/files/(original|large)/ { rewrite ^/files/(.*)$ /' . self::MODULE_ROUTES[$module] . '$1 last; }'],
                ];
            }
            return $issues;
        }

        if ($server !== '' && strpos($server, 'apache') === false && strpos($server, 'litespeed') === false) {
            $issues[] = [
                'warning',
                'The web server is "{server}": check that it applies the files ".htaccess" of Omeka and of "files/", else configure the protections in its own config.', // @translate
                ['server' => $serverSoftware],
            ];
        }

        if ($htaccess === null) {
            $issues[] = ['error', 'The file ".htaccess" is missing at the root of Omeka: the urls and the protections do not work.', []]; // @translate
            return $issues;
        }

        $lines = preg_split('~\R~', $htaccess);
        $engineLine = null;
        $existingFileLine = null;
        $rules = [];
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($engineLine === null && preg_match('~^RewriteEngine\s+On\b~i', $line)) {
                $engineLine = $index;
            }
            // The core rule that serves the existing files directly.
            if ($existingFileLine === null && preg_match('~^RewriteCond\s+%\{REQUEST_FILENAME\}\s+-f\b~i', $line)) {
                $existingFileLine = $index;
            }
            if (preg_match('~^RewriteRule\s+"?\^?/?files/(?:\(([^)]+)\)|([a-z0-9_-]+))/[^\s"]*"?\s+"?/?([^\s"]+)~i', $line, $matches)) {
                foreach (self::MODULE_ROUTES as $module => $route) {
                    if (strpos(ltrim($matches[3], '/'), $route) !== false
                        || preg_match('~/' . preg_quote($route, '~') . '~', $matches[3])
                    ) {
                        $types = $matches[1] !== '' ? explode('|', $matches[1]) : [$matches[2]];
                        $rules[] = ['module' => $module, 'types' => $types, 'line' => $index];
                        break;
                    }
                }
            }
        }

        if ($engineLine === null) {
            $issues[] = ['error', 'The file ".htaccess" does not enable the rewrite engine ("RewriteEngine On"): the urls of Omeka do not work.', []]; // @translate
        }

        $modulesWithRule = array_unique(array_column($rules, 'module'));
        foreach (array_intersect(array_keys(self::MODULE_ROUTES), $activeModules) as $module) {
            // The downloads of the files protected by the module Access are
            // counted by the module Analytics too.
            $hasRule = in_array($module, $modulesWithRule, true)
                || ($module === 'Analytics' && in_array('Access', $activeModules, true) && in_array('Access', $modulesWithRule, true));
            if (!$hasRule) {
                $issues[] = [
                    'warning',
                    $module === 'Access'
                        ? 'The module Access is active, but the file ".htaccess" has no rule to redirect "files/" to it: the files are not protected.' // @translate
                        : 'The module Analytics is active, but the file ".htaccess" has no rule to redirect "files/" to it: the downloads are not counted.', // @translate
                    [],
                ];
            }
        }

        foreach ($rules as $rule) {
            if (!in_array($rule['module'], $activeModules, true)) {
                $issues[] = [
                    'error',
                    'The file ".htaccess" redirects the files {types} to the module {module} (line {line}), that is not active: these files are not available.', // @translate
                    ['types' => implode(', ', $rule['types']), 'module' => $rule['module'], 'line' => $rule['line'] + 1],
                ];
            }
            if ($existingFileLine !== null && $rule['line'] > $existingFileLine) {
                $issues[] = [
                    'error',
                    'The rule of the module {module} (line {line}) is placed after the rule that serves the existing files directly (line {line_core}), so it is never applied: move it just after "RewriteEngine On".', // @translate
                    ['module' => $rule['module'], 'line' => $rule['line'] + 1, 'line_core' => $existingFileLine + 1],
                ];
            }
        }

        // A type is handled by the first matching rule only.
        $firstByType = [];
        foreach ($rules as $rule) {
            foreach ($rule['types'] as $type) {
                $firstByType[$type] ??= $rule;
            }
        }
        foreach ($rules as $rule) {
            foreach ($rule['types'] as $type) {
                $first = $firstByType[$type];
                if ($first === $rule) {
                    continue;
                }
                if ($first['module'] === 'Analytics' && $rule['module'] === 'Access') {
                    $issues[] = [
                        'error',
                        'The files "{type}" are redirected to the module Analytics (line {line}) before the module Access (line {line_access}): the protection of these files is bypassed. Place the rule of Access first.', // @translate
                        ['type' => $type, 'line' => $first['line'] + 1, 'line_access' => $rule['line'] + 1],
                    ];
                } elseif ($first['module'] !== $rule['module']) {
                    $issues[] = [
                        'notice',
                        'The files "{type}" are redirected to the module {module} (line {line}), so the rule of the module {module_2} (line {line_2}) is not used for them.', // @translate
                        ['type' => $type, 'module' => $first['module'], 'line' => $first['line'] + 1, 'module_2' => $rule['module'], 'line_2' => $rule['line'] + 1],
                    ];
                }
            }
        }

        return $issues;
    }
}

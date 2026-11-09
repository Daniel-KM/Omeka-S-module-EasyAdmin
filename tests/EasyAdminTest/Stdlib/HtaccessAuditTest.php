<?php declare(strict_types=1);

namespace EasyAdminTest\Stdlib;

use EasyAdmin\Stdlib\HtaccessAudit;
use PHPUnit\Framework\TestCase;

class HtaccessAuditTest extends TestCase
{
    const CORE = <<<'HTACCESS'
        RewriteCond %{REQUEST_FILENAME} -f
        RewriteRule !\.(php[0-9]?|phtml|phps|phar|hphp)$ - [NC,C]
        RewriteRule .* - [L]
        RewriteRule ^(.*)$ %{ENV:BASE}/index.php [E=OMEKA_REQ:"1",L]
        HTACCESS;

    const ACCESS = <<<'HTACCESS'
        # Module Access: protect files.
        RewriteRule "^files/(original|large)/(.*)$" "access/files/$1/$2" [NC,L]
        # /Module Access: protect files.
        HTACCESS;

    const ANALYTICS = <<<'HTACCESS'
        # Module Analytics: count downloads.
        RewriteRule "^files/(original)/(.*)$" "download/files/$1/$2" [NC,L]
        HTACCESS;

    protected function messages(?string $htaccess, array $modules, string $server = 'Apache/2.4'): array
    {
        return array_map(
            fn ($issue) => $issue[0] . ': ' . $issue[1],
            (new HtaccessAudit())->audit($htaccess, $modules, $server)
        );
    }

    protected function assertIssue(string $level, string $text, array $messages): void
    {
        $found = array_filter($messages, fn ($m) => strpos($m, $level . ': ') === 0 && strpos($m, $text) !== false);
        $this->assertNotEmpty($found, "No $level containing \"$text\" in:\n" . implode("\n", $messages));
    }

    public function testCompatible(): void
    {
        $htaccess = "RewriteEngine On\n" . self::ACCESS . "\n" . self::CORE;
        $this->assertSame([], $this->messages($htaccess, ['Access']));
    }

    public function testNginxIgnoresHtaccess(): void
    {
        $messages = $this->messages(null, ['Access'], 'nginx/1.24');
        $this->assertIssue('error', 'Nginx', $messages);
        $this->assertIssue('warning', 'module {module} requires a rewrite', $messages);
    }

    public function testMissingFileAndEngine(): void
    {
        $this->assertIssue('error', 'is missing at the root', $this->messages(null, []));
        $this->assertIssue('error', 'RewriteEngine On', $this->messages(self::CORE, []));
    }

    public function testActiveModuleWithoutRule(): void
    {
        $htaccess = "RewriteEngine On\n" . self::CORE;
        $this->assertIssue('warning', 'files are not protected', $this->messages($htaccess, ['Access']));
        $this->assertIssue('warning', 'downloads are not counted', $this->messages($htaccess, ['Analytics']));
    }

    public function testAnalyticsCountsTheFilesOfAccess(): void
    {
        $htaccess = "RewriteEngine On\n" . self::ACCESS . "\n" . self::CORE;
        $this->assertSame([], $this->messages($htaccess, ['Access', 'Analytics']));
    }

    public function testRuleOfInactiveModule(): void
    {
        $htaccess = "RewriteEngine On\n" . self::ACCESS . "\n" . self::CORE;
        $this->assertIssue('error', 'that is not active', $this->messages($htaccess, []));
    }

    public function testRuleAfterTheCoreRule(): void
    {
        $htaccess = "RewriteEngine On\n" . self::CORE . "\n" . self::ACCESS;
        $this->assertIssue('error', 'never applied', $this->messages($htaccess, ['Access']));
    }

    public function testAnalyticsBeforeAccessBypassesTheProtection(): void
    {
        $htaccess = "RewriteEngine On\n" . self::ANALYTICS . "\n" . self::ACCESS . "\n" . self::CORE;
        $this->assertIssue('error', 'protection of these files is bypassed', $this->messages($htaccess, ['Access', 'Analytics']));
    }

    public function testAccessBeforeAnalyticsIsANotice(): void
    {
        $htaccess = "RewriteEngine On\n" . self::ACCESS . "\n" . self::ANALYTICS . "\n" . self::CORE;
        $messages = $this->messages($htaccess, ['Access', 'Analytics']);
        $this->assertIssue('notice', 'is not used for them', $messages);
        $this->assertEmpty(array_filter($messages, fn ($m) => strpos($m, 'error: ') === 0));
    }

    public function testOtherServer(): void
    {
        $htaccess = "RewriteEngine On\n" . self::CORE;
        $this->assertIssue('warning', 'The web server is "{server}"', $this->messages($htaccess, [], 'Caddy'));
    }
}

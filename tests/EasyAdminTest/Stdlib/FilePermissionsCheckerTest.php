<?php declare(strict_types=1);

namespace EasyAdminTest\Stdlib;

use EasyAdmin\Stdlib\FilePermissionsChecker;
use PHPUnit\Framework\TestCase;

class FilePermissionsCheckerTest extends TestCase
{
    /**
     * @var string
     */
    protected $dir;

    /**
     * @var FilePermissionsChecker
     */
    protected $checker;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/easyadmin_permissions_' . bin2hex(random_bytes(4));
        foreach (['application/src', 'modules/Foo/language', 'modules/Foo/src/Sub', 'modules/Foo/node_modules', 'themes/bar/view', 'files/original', 'logs'] as $path) {
            mkdir($this->dir . '/' . $path, 0777, true);
        }
        foreach (['application/src/A.php', 'modules/Foo/language/fr.mo', 'modules/Foo/src/Sub/B.php', 'modules/Foo/node_modules/c.js', 'themes/bar/view/d.phtml'] as $path) {
            file_put_contents($this->dir . '/' . $path, '');
        }
        $this->checker = new FilePermissionsChecker();
    }

    public function tearDown(): void
    {
        exec('chmod -R u+rwx ' . escapeshellarg($this->dir) . ' && rm -rf ' . escapeshellarg($this->dir));
    }

    public function testCodeDirectories(): void
    {
        $directories = $this->checker->codeDirectories($this->dir);
        sort($directories);
        $this->assertSame([
            $this->dir . '/application',
            $this->dir . '/modules/Foo',
            $this->dir . '/themes/bar',
        ], $directories);
    }

    public function testWritableDirectories(): void
    {
        $this->assertSame([
            $this->dir . '/files',
            $this->dir . '/files/original',
            $this->dir . '/logs',
        ], $this->checker->writableDirectories($this->dir, $this->dir . '/files'));
    }

    public function testReadableFilesAreNotListed(): void
    {
        $this->assertSame([], $this->checker->unreadable($this->checker->codeDirectories($this->dir)));
    }

    public function testUnreadableFilesAndDirectoriesAreListedByDirectory(): void
    {
        chmod($this->dir . '/modules/Foo/language/fr.mo', 0);
        chmod($this->dir . '/modules/Foo/src/Sub', 0);
        chmod($this->dir . '/modules/Foo/node_modules/c.js', 0);
        chmod($this->dir . '/themes/bar/view/d.phtml', 0);
        if (is_readable($this->dir . '/modules/Foo/language/fr.mo')) {
            $this->markTestSkipped('The permissions are not applied for this user.');
        }

        $this->assertSame([
            $this->dir . '/modules/Foo' => [
                $this->dir . '/modules/Foo/language/fr.mo',
                $this->dir . '/modules/Foo/src/Sub',
            ],
            $this->dir . '/themes/bar' => [
                $this->dir . '/themes/bar/view/d.phtml',
            ],
        ], $this->checker->unreadable([
            $this->dir . '/application',
            $this->dir . '/modules/Foo',
            $this->dir . '/themes/bar',
        ]));
    }

    public function testUnwritableDirectoriesAreListed(): void
    {
        chmod($this->dir . '/files/original', 0555);
        if (is_writable($this->dir . '/files/original')) {
            $this->markTestSkipped('The permissions are not applied for this user.');
        }

        $this->assertSame(
            [$this->dir . '/files/original'],
            $this->checker->unwritable($this->checker->writableDirectories($this->dir, $this->dir . '/files'))
        );
    }
}

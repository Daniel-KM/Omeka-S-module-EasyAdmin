<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdmin\Controller\Admin\CheckAndFixController;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Check and Fix Controller.
 */
class CheckAndFixControllerTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test check and fix index page is accessible.
     */
    public function testCheckAndFixIndexAction(): void
    {
        $this->dispatch('/admin/easy-admin/check-and-fix');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('EasyAdmin\Controller\Admin\CheckAndFix');
        $this->assertActionName('index');
    }

    /**
     * Test check and fix page requires authentication.
     */
    public function testCheckAndFixRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/check-and-fix');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test check and fix page contains form.
     */
    public function testCheckAndFixContainsForm(): void
    {
        $this->dispatch('/admin/easy-admin/check-and-fix');

        $this->assertResponseStatusCode(200);
        $this->assertQuery('#check-and-fix-form');
    }

    /**
     * A quick process runs in the web process, so it must be a real process of
     * the controller: a renamed or removed case would silently fall back to
     * the background dispatch.
     */
    public function testQuickProcessesAreHandledByTheController(): void
    {
        $source = file_get_contents(
            OMEKA_PATH . '/modules/EasyAdmin/src/Controller/Admin/CheckAndFixController.php'
        );
        foreach (CheckAndFixController::QUICK_PROCESSES as $process) {
            $this->assertStringContainsString(
                "case '" . $process . "':",
                $source,
                sprintf('The quick process "%s" is not handled by the controller.', $process)
            );
        }
    }

    /**
     * A quick process must not loop on the resources or the files: only bounded
     * sql or a loop on a fixed list may run in the web process.
     */
    public function testQuickProcessesAreNotFileProcesses(): void
    {
        foreach (CheckAndFixController::QUICK_PROCESSES as $process) {
            $this->assertStringStartsWith(
                'db_',
                $process,
                sprintf('The quick process "%s" is not a database process.', $process)
            );
        }
    }

    /**
     * The quick processes are flagged in the page, so the user knows which
     * tasks return their result immediately.
     */
    public function testQuickProcessesAreFlaggedInThePage(): void
    {
        $this->dispatch('/admin/easy-admin/check-and-fix');

        $this->assertResponseStatusCode(200);
        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('data-quick-processes', $body);
        foreach (CheckAndFixController::QUICK_PROCESSES as $process) {
            $this->assertStringContainsString($process, $body);
        }
    }

    /**
     * The modes "test" and "production" of the environment settings set and
     * revert the settings that distinguish a test copy, files included.
     */
    public function testEnvironmentSettingsForTestAndProduction(): void
    {
        $app = $this->getApplication();
        $services = $app->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $keys = [
            'installation_title', 'easyadmin_admin_board_color', 'easyadmin_display_exception',
            'easyadmin_local_store_base_uri', 'easyadmin_local_store_fake_images',
            'easyadmin_mail_test_copy', 'easyadmin_mail_redirect_to',
        ];
        $previous = array_combine($keys, array_map([$settings, 'get'], $keys));

        $controller = $services->get('ControllerManager')->get(CheckAndFixController::class);
        $event = new \Laminas\Mvc\MvcEvent();
        $event->setApplication($app);
        $controller->setEvent($event);
        $controller->setPluginManager($services->get('ControllerPluginManager'));
        $method = new \ReflectionMethod($controller, 'checkSettingsEnvironment');
        $method->setAccessible(true);

        try {
            $settings->set('installation_title', 'Site');
            $settings->set('easyadmin_admin_board_color', '');
            $settings->set('easyadmin_display_exception', '');
            $settings->set('easyadmin_local_store_base_uri', '');
            $settings->set('easyadmin_local_store_fake_images', '');
            $settings->set('easyadmin_mail_test_copy', '');
            $settings->set('easyadmin_mail_redirect_to', '');

            $method->invoke($controller, 'test', false);
            $this->assertSame('redirect', $settings->get('easyadmin_mail_test_copy'));
            $this->assertSame(
                $services->get('Omeka\AuthenticationService')->getIdentity()->getEmail(),
                $settings->get('easyadmin_mail_redirect_to')
            );
            $this->assertSame('TEST Site', $settings->get('installation_title'));
            $this->assertSame(CheckAndFixController::TEST_BOARD_COLOR, $settings->get('easyadmin_admin_board_color'));
            $this->assertSame('all', $settings->get('easyadmin_display_exception'));
            $this->assertSame('placeholder', $settings->get('easyadmin_local_store_fake_images'));

            $settings->set('easyadmin_local_store_base_uri', 'https://example.org/files');
            $method->invoke($controller, 'production', false);
            $this->assertSame('Site', $settings->get('installation_title'));
            $this->assertSame('', $settings->get('easyadmin_admin_board_color'));
            $this->assertSame('', $settings->get('easyadmin_display_exception'));
            $this->assertSame('', $settings->get('easyadmin_local_store_base_uri'));
            $this->assertSame('', $settings->get('easyadmin_local_store_fake_images'));
            $this->assertSame('', $settings->get('easyadmin_mail_test_copy'));

            // The files served from another server are kept for a test copy.
            $settings->set('easyadmin_local_store_base_uri', 'https://example.org/files');
            $method->invoke($controller, 'test', false);
            $this->assertSame('', $settings->get('easyadmin_local_store_fake_images'));
        } finally {
            foreach ($previous as $key => $value) {
                $settings->set($key, $value);
            }
            $services->get('ControllerPluginManager')->get('messenger')->clear();
        }
    }

    protected function checkAndFixController(): CheckAndFixController
    {
        $app = $this->getApplication();
        $services = $app->getServiceManager();
        $controller = $services->get('ControllerManager')->get(CheckAndFixController::class);
        $event = new \Laminas\Mvc\MvcEvent();
        $event->setApplication($app);
        $controller->setEvent($event);
        $controller->setPluginManager($services->get('ControllerPluginManager'));
        return $controller;
    }

    /**
     * The proxy and the certificates of the http client are read from the
     * config and the environment, with redacted credentials.
     */
    public function testHttpClientInfo(): void
    {
        $controller = $this->checkAndFixController();
        $services = $this->getApplication()->getServiceManager();
        $method = new \ReflectionMethod($controller, 'httpClientInfo');
        $method->setAccessible(true);
        $config = $services->get('Config');
        $previousEnv = getenv('https_proxy');
        $services->setAllowOverride(true);

        try {
            // A proxy of the environment is ignored by the adapter socket.
            putenv('https_proxy=http://user:secret@proxy.example.org:3128');
            $services->setService('Config', ['http_client' => ['adapter' => 'socket', 'sslcafile' => '/nonexistent/ca.crt']] + $config);
            $info = $method->invoke($controller);
            $this->assertSame('Socket', $info['adapter']);
            $this->assertNull($info['proxy']);
            $this->assertTrue($info['ignoredEnv']);
            $this->assertSame('http://***@proxy.example.org:3128', $info['env']['https_proxy']);
            $this->assertSame(['sslcafile' => '/nonexistent/ca.crt'], $info['sslMissing']);

            // The proxy of the config is used by curl.
            $services->setService('Config', ['http_client' => [
                'adapter' => 'curl',
                'proxy_host' => 'proxy.local',
                'proxy_port' => 3128,
                'proxy_user' => 'user',
            ]] + $config);
            $info = $method->invoke($controller);
            $this->assertSame('Curl', $info['adapter']);
            $this->assertSame('***@proxy.local:3128', $info['proxy']);
            $this->assertFalse($info['ignoredEnv']);
            $this->assertSame([], $info['sslMissing']);
        } finally {
            putenv($previousEnv === false ? 'https_proxy' : 'https_proxy=' . $previousEnv);
            $services->setService('Config', $config);
            $services->setAllowOverride(false);
        }
    }

    /**
     * The installation check reports the http client and the access to the web,
     * whatever the network.
     */
    public function testInstallCheckReportsTheHttpClient(): void
    {
        $controller = $this->checkAndFixController();
        $messenger = $this->getApplication()->getServiceManager()->get('ControllerPluginManager')->get('messenger');
        $messenger->clear();
        $method = new \ReflectionMethod($controller, 'checkHttpClient');
        $method->setAccessible(true);
        $method->invoke($controller);

        $text = '';
        foreach ($messenger->get() as $messages) {
            foreach ($messages as $message) {
                $text .= $message . "\n";
            }
        }
        $messenger->clear();
        $this->assertStringContainsString('The http client uses the adapter', $text);
        $this->assertMatchesRegularExpression('~can access the web|cannot access the web~', $text);
    }

    /**
     * The audit of the emails counts the accounts and the recipients outside
     * the domains of the team.
     */
    public function testMailAudit(): void
    {
        $controller = $this->checkAndFixController();
        $services = $this->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $previous = $settings->get('easyadmin_mail_team_domains');
        $settings->set('easyadmin_mail_team_domains', 'team.example.org');
        $settings->set('easyadmin_test_recipient', 'someone@users.example.org');
        $method = new \ReflectionMethod($controller, 'mailAudit');
        $method->setAccessible(true);

        try {
            $audit = $method->invoke($controller);
            $this->assertSame(['team.example.org'], $audit['domains']);
            $this->assertGreaterThanOrEqual(1, $audit['users']['total']);
            // The admin of the tests (example.com) is outside the team.
            $this->assertGreaterThanOrEqual(1, $audit['users']['outside']);
            $this->assertContains('someone@users.example.org', $audit['recipients']);
        } finally {
            $settings->set('easyadmin_mail_team_domains', $previous);
            $settings->delete('easyadmin_test_recipient');
        }
    }

    public function testMailerIsProtectedOnATestCopy(): void
    {
        $settings = $this->getApplication()->getServiceManager()->get('Omeka\Settings');
        $settings->set('easyadmin_mail_test_copy', 'redirect');
        $settings->set('easyadmin_mail_redirect_to', 'tester@team.example.org');
        try {
            $this->reset();
            $mailer = $this->getApplication()->getServiceManager()->get('Omeka\Mailer');
            $this->assertInstanceOf(\EasyAdmin\Mail\TestCopyTransport::class, $mailer->getTransport());
        } finally {
            $settings = $this->getApplication()->getServiceManager()->get('Omeka\Settings');
            $settings->set('easyadmin_mail_test_copy', '');
            $settings->set('easyadmin_mail_redirect_to', '');
        }
    }

    /**
     * The images of a copy with its files are not replaced in mode test.
     */
    public function testTestModeKeepsTheImagesOfACopyWithFiles(): void
    {
        $controller = $this->checkAndFixController();
        $services = $this->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $connection = $services->get('Omeka\Connection');
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $previous = [
            'easyadmin_local_store_fake_images' => $settings->get('easyadmin_local_store_fake_images'),
            'easyadmin_local_store_base_uri' => $settings->get('easyadmin_local_store_base_uri'),
            'easyadmin_mail_test_copy' => $settings->get('easyadmin_mail_test_copy'),
            'easyadmin_mail_redirect_to' => $settings->get('easyadmin_mail_redirect_to'),
            'easyadmin_admin_board_color' => $settings->get('easyadmin_admin_board_color'),
            'easyadmin_display_exception' => $settings->get('easyadmin_display_exception'),
            'installation_title' => $settings->get('installation_title'),
        ];

        $item = $services->get('Omeka\ApiManager')->create('items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Test</p>']],
        ])->getContent();
        $mediaId = $item->primaryMedia()->id();
        $storageId = 'easyadmin-test-' . uniqid();
        $connection->executeStatement(
            'UPDATE media SET has_original = 1, storage_id = ?, extension = ? WHERE id = ?',
            [$storageId, 'jpg', $mediaId]
        );
        $file = $basePath . '/original/' . $storageId . '.jpg';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        touch($file);

        $method = new \ReflectionMethod($controller, 'checkSettingsEnvironment');
        $method->setAccessible(true);
        try {
            $settings->set('easyadmin_local_store_fake_images', '');
            $settings->set('easyadmin_local_store_base_uri', '');
            $method->invoke($controller, 'test', false);
            $this->assertSame('', $settings->get('easyadmin_local_store_fake_images'));
        } finally {
            @unlink($file);
            $services->get('Omeka\ApiManager')->delete('items', $item->id());
            foreach ($previous as $key => $value) {
                $settings->set($key, $value);
            }
            $services->get('ControllerPluginManager')->get('messenger')->clear();
        }
    }

}

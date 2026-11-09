<?php declare(strict_types=1);

namespace EasyAdmin\Service\Mail;

use EasyAdmin\Mail\TestCopyTransport;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use Omeka\Stdlib\Mailer;
use Psr\Container\ContainerInterface;

/**
 * Wrap the transport of the mailer to protect the real users of a test copy.
 *
 * All the emails of the core and of the modules are sent through the service
 * "Omeka\Mailer", so they are all managed.
 */
class MailerTestCopyDelegatorFactory implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name, callable $callback, ?array $options = null)
    {
        $mailer = $callback();
        if (!$mailer instanceof Mailer) {
            return $mailer;
        }

        try {
            $settings = $container->get('Omeka\Settings');
            $mode = (string) $settings->get('easyadmin_mail_test_copy', '');
        } catch (\Throwable $e) {
            return $mailer;
        }
        if (!in_array($mode, TestCopyTransport::MODES, true)) {
            return $mailer;
        }

        $config = $container->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $capturePath = $basePath . '/mailcapture';
        if ($mode === 'capture' || trim((string) $settings->get('easyadmin_mail_redirect_to', '')) === '') {
            $container->get('Common\DirectoryManager')->checkDestinationDir($capturePath, true);
        }

        $transport = new TestCopyTransport(
            $mailer->getTransport(),
            $mode,
            trim((string) $settings->get('easyadmin_mail_redirect_to', '')),
            self::teamDomains($settings),
            $capturePath,
            $container->get('Omeka\Logger')
        );

        $property = new \ReflectionProperty(Mailer::class, 'transport');
        $property->setAccessible(true);
        $property->setValue($mailer, $transport);

        return $mailer;
    }

    /**
     * Get the domains of the team, else the one of the administrator email.
     *
     * @return string[]
     */
    public static function teamDomains($settings): array
    {
        $domains = preg_split('~[\s,;]+~', strtolower((string) $settings->get('easyadmin_mail_team_domains', '')), -1, PREG_SPLIT_NO_EMPTY);
        if (!$domains) {
            $email = (string) $settings->get('administrator_email', '');
            $domain = substr((string) strrchr($email, '@'), 1);
            $domains = $domain === '' ? [] : [strtolower($domain)];
        }
        return array_values(array_unique(array_map(fn ($domain) => ltrim($domain, '@'), $domains)));
    }
}

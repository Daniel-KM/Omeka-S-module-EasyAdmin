<?php declare(strict_types=1);

namespace EasyAdmin\Mvc\Controller\Plugin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Settings\Settings;

/**
 * Check mailer configuration and DNS records for email deliverability.
 */
class CheckMailer extends AbstractPlugin
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Messenger
     */
    protected $messenger;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    public function __construct(
        array $config,
        Messenger $messenger,
        Settings $settings
    ) {
        $this->config = $config;
        $this->messenger = $messenger;
        $this->settings = $settings;
    }

    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Get the current mail configuration summary.
     *
     * @return array List of PsrMessage objects describing the configuration.
     */
    public function getConfigSummary(): array
    {
        $configSummary = [];

        $senderEmail = $this->getSenderEmail();
        $configSummary[] = new PsrMessage(
            'Sender email: {email}', // @translate
            ['email' => $senderEmail]
        );

        $mailConfig = $this->config['mail'] ?? [];
        $transportConfig = $mailConfig['transport'] ?? [];
        $transportType = $transportConfig['type'] ?? 'sendmail';

        $configSummary[] = new PsrMessage(
            'Transport type: {value}', // @translate
            ['value' => $transportType]
        );

        if ($transportType === 'smtp') {
            $transportOptions = $transportConfig['options'] ?? [];

            if (!empty($transportOptions['host'])) {
                $configSummary[] = new PsrMessage(
                    'SMTP host: {value}', // @translate
                    ['value' => $transportOptions['host']]
                );
            }
            if (!empty($transportOptions['port'])) {
                $configSummary[] = new PsrMessage(
                    'SMTP port: {value}', // @translate
                    ['value' => $transportOptions['port']]
                );
            }
            if (!empty($transportOptions['name'])) {
                $configSummary[] = new PsrMessage(
                    'SMTP name (HELO): {value}', // @translate
                    ['value' => $transportOptions['name']]
                );
            }

            // Connection security settings.
            $connectionConfig = $transportOptions['connection_config'] ?? [];
            $connectionClass = $transportOptions['connection_class'] ?? 'smtp';
            $configSummary[] = new PsrMessage(
                'Connection class: {value}', // @translate
                ['value' => $connectionClass]
            );

            if (!empty($connectionConfig['ssl'])) {
                $ssl = $connectionConfig['ssl'];
                $securityLabel = $ssl === 'ssl' ? 'SSL/TLS (port 465)' : ($ssl === 'tls' ? 'STARTTLS (port 587)' : $ssl);
                $configSummary[] = new PsrMessage(
                    'Security: {value}', // @translate
                    ['value' => $securityLabel]
                );
            } else {
                $configSummary[] = new PsrMessage(
                    'Security: None (not recommended)' // @translate
                );
            }

            if (!empty($connectionConfig['username'])) {
                $configSummary[] = new PsrMessage(
                    'Authentication: Yes (user: {value})', // @translate
                    ['value' => $connectionConfig['username']]
                );
            } else {
                $configSummary[] = new PsrMessage(
                    'Authentication: No' // @translate
                );
            }
        }

        return $configSummary;
    }

    /**
     * Get the configured sender email.
     */
    public function getSenderEmail(): string
    {
        $mailConfig = $this->config['mail'] ?? [];
        $defaultOptions = $mailConfig['default_message_options'] ?? [];
        return $defaultOptions['from'] ?? $this->settings->get('administrator_email') ?? '';
    }

    /**
     * Get the configured transport type.
     */
    public function getTransportType(): string
    {
        $mailConfig = $this->config['mail'] ?? [];
        $transportConfig = $mailConfig['transport'] ?? [];
        return $transportConfig['type'] ?? 'sendmail';
    }

    /**
     * Get the configured SMTP host.
     */
    public function getSmtpHost(): ?string
    {
        $mailConfig = $this->config['mail'] ?? [];
        $transportConfig = $mailConfig['transport'] ?? [];
        return $transportConfig['options']['host'] ?? null;
    }

    /**
     * Get the mail server IP address.
     *
     * Tries to detect the IP in this order:
     * 1. From SMTP host configuration (if it's an IP or resolvable hostname)
     * 2. From the current server's IP address
     * 3. From the A record of the mail domain
     *
     * @param string|null $domain Optional domain to check A record
     * @return string|null The detected IP address or null
     */
    public function getMailServerIp(?string $domain = null): ?string
    {
        // 1. Try SMTP host from config.
        $mailConfig = $this->config['mail'] ?? [];
        $transportConfig = $mailConfig['transport'] ?? [];
        if (($transportConfig['type'] ?? '') === 'smtp') {
            $smtpHost = $transportConfig['options']['host'] ?? '';
            if ($smtpHost) {
                // If it's already an IP, return it.
                if (filter_var($smtpHost, FILTER_VALIDATE_IP)) {
                    return $smtpHost;
                }
                // Try to resolve the hostname.
                $ip = @gethostbyname($smtpHost);
                if ($ip && $ip !== $smtpHost) {
                    return $ip;
                }
            }
        }

        // 2. Try current server's IP.
        $serverIp = $_SERVER['SERVER_ADDR'] ?? null;
        if ($serverIp && filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $serverIp;
        }

        // Try via gethostname.
        $hostname = @gethostname();
        if ($hostname) {
            $ip = @gethostbyname($hostname);
            if ($ip && $ip !== $hostname && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // 3. Try A record of domain.
        if ($domain) {
            $aRecords = @dns_get_record($domain, DNS_A);
            if ($aRecords && !empty($aRecords[0]['ip'])) {
                return $aRecords[0]['ip'];
            }
        }

        return null;
    }

    /**
     * Check DNS records for email deliverability.
     *
     * @param array $options Options: domain, ip_address, dkim_selector
     * @return array Results with status for each record type.
     */
    public function checkDns(array $options = []): array
    {
        $results = [
            'domain' => null,
            'spf' => ['valid' => false, 'record' => null, 'recommendation' => null],
            'dkim' => ['valid' => false, 'record' => null, 'recommendation' => null, 'selector' => null],
            'dmarc' => ['valid' => false, 'record' => null, 'recommendation' => null],
            'ptr' => ['valid' => false, 'hostname' => null, 'matches_domain' => false],
        ];

        // Determine the domain to check.
        $domain = trim($options['domain'] ?? '');
        if (empty($domain)) {
            $senderEmail = $this->getSenderEmail();
            if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                $domain = substr(strrchr($senderEmail, '@'), 1);
            }
        }

        if (empty($domain)) {
            return $results;
        }

        $results['domain'] = $domain;

        // Get IP address: from options, or auto-detect.
        $ipAddress = trim($options['ip_address'] ?? '');
        if (empty($ipAddress)) {
            $ipAddress = $this->getMailServerIp($domain);
        }
        $results['ip_address'] = $ipAddress;

        $dkimSelector = trim($options['dkim_selector'] ?? '') ?: 'mail';
        $adminEmail = 'admin@' . $domain;

        // Check SPF record.
        $spfRecords = $this->getDnsTxtRecords($domain);
        foreach ($spfRecords as $record) {
            if (strpos($record, 'v=spf1') === 0) {
                $results['spf']['valid'] = true;
                $results['spf']['record'] = $record;
                break;
            }
        }
        if (!$results['spf']['valid']) {
            $results['spf']['recommendation'] = $ipAddress
                ? "v=spf1 ip4:$ipAddress ~all"
                : 'v=spf1 include:_spf.example.com ~all';
        }

        // Check DKIM record.
        $dkimDomain = "$dkimSelector._domainkey.$domain";
        $results['dkim']['selector'] = $dkimSelector;
        $results['dkim']['dkim_domain'] = $dkimDomain;
        $dkimRecords = $this->getDnsTxtRecords($dkimDomain);
        foreach ($dkimRecords as $record) {
            if (strpos($record, 'v=DKIM1') === 0 && strpos($record, 'p=') !== false) {
                $results['dkim']['valid'] = true;
                $results['dkim']['record'] = $record;
                break;
            }
        }
        if (!$results['dkim']['valid']) {
            $results['dkim']['recommendation'] = 'v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY';
        }

        // Check DMARC record.
        $dmarcDomain = "_dmarc.$domain";
        $results['dmarc']['dmarc_domain'] = $dmarcDomain;
        $dmarcRecords = $this->getDnsTxtRecords($dmarcDomain);
        foreach ($dmarcRecords as $record) {
            if (strpos($record, 'v=DMARC1') === 0) {
                $results['dmarc']['valid'] = true;
                $results['dmarc']['record'] = $record;
                break;
            }
        }
        if (!$results['dmarc']['valid']) {
            $results['dmarc']['recommendation'] = "v=DMARC1; p=none; rua=mailto:$adminEmail; ruf=mailto:$adminEmail; pct=100";
        }

        // Check reverse DNS (PTR record) if IP is provided.
        if ($ipAddress && filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $reverseDns = @gethostbyaddr($ipAddress);
            if ($reverseDns && $reverseDns !== $ipAddress) {
                $results['ptr']['valid'] = true;
                $results['ptr']['hostname'] = $reverseDns;
                $results['ptr']['matches_domain'] = strpos($reverseDns, $domain) !== false;
                // Detect hosting provider.
                $results['ptr']['provider'] = $this->detectHostingProvider($reverseDns);
            }
            $results['ptr']['ip'] = $ipAddress;
        }

        return $results;
    }

    /**
     * Detect hosting provider from PTR hostname.
     *
     * Only detects known provider patterns from auto-assigned PTR hostnames.
     * Custom PTR hostnames (like "mail.example.com") cannot reliably identify
     * the provider.
     *
     * @param string $hostname The PTR hostname
     * @return string|null The provider name or null if unknown
     */
    protected function detectHostingProvider(string $hostname): ?string
    {
        $providers = [
            '1and1.com' => 'IONOS',
            'akamai.com' => 'Akamai/Linode',
            'amazonaws.com' => 'AWS',
            'azure.com' => 'Microsoft Azure',
            'cloudapp.azure.com' => 'Microsoft Azure',
            'digitalocean.com' => 'DigitalOcean',
            'gandi.net' => 'Gandi',
            'google.com' => 'Google Cloud',
            'googleusercontent.com' => 'Google Cloud',
            'hetzner.com' => 'Hetzner',
            'infomaniak.com' => 'Infomaniak',
            'ionos.com' => 'IONOS',
            'kimsufi.com' => 'OVH Kimsufi',
            'linode.com' => 'Linode',
            'online.net' => 'Scaleway Dedibox',
            'ovh.com' => 'OVH',
            'ovh.eu' => 'OVH',
            'ovh.net' => 'OVH',
            'poneytelecom.eu' => 'Scaleway Dedibox',
            'scaleway.com' => 'Scaleway',
            'scw.cloud' => 'Scaleway',
            'soyoustart.com' => 'OVH SoYouStart',
            'vultr.com' => 'Vultr',
            'your-server.de' => 'Hetzner',
        ];

        $hostname = strtolower($hostname);
        foreach ($providers as $pattern => $name) {
            if (strpos($hostname, $pattern) !== false) {
                return $name;
            }
        }

        // Don't fallback to extracting domain from custom PTR hostnames.
        // A custom PTR like "mail.galae.net" doesn't identify the hosting provider.
        return null;
    }

    /**
     * Detect domain registrar/DNS provider from NS records.
     *
     * @param string $domain The domain name
     * @return string|null The registrar/DNS provider name or null if unknown
     */
    protected function detectDomainRegistrar(string $domain): ?string
    {
        $registrars = [
            'anycast.me' => 'OVH',
            'awsdns' => 'AWS Route 53',
            'azure-dns' => 'Azure DNS',
            'bluehost.com' => 'Bluehost',
            'bookmyname.com' => 'BookMyName',
            'cloudflare.com' => 'Cloudflare',
            'digitalocean.com' => 'DigitalOcean',
            'domaincontrol.com' => 'GoDaddy',
            'gandi.net' => 'Gandi',
            'godaddy.com' => 'GoDaddy',
            'google.com' => 'Google Cloud DNS',
            'googledomains.com' => 'Google Domains',
            'hetzner.com' => 'Hetzner',
            'hostinger' => 'Hostinger',
            'infomaniak.ch' => 'Infomaniak',
            'n0c.com' => 'PlanetHoster',
            'namecheaphosting.com' => 'Namecheap',
            'netlify.com' => 'Netlify',
            'online.net' => 'Scaleway',
            'ovh.com' => 'OVH',
            'ovh.net' => 'OVH',
            'planethoster.net' => 'PlanetHoster',
            'poneytelecom.eu' => 'Scaleway',
            'registrar-servers.com' => 'Namecheap',
            'siteground' => 'SiteGround',
            'ui-dns.com' => 'IONOS',
            'ui-dns.de' => 'IONOS',
            'ui-dns.org' => 'IONOS',
            'vercel-dns.com' => 'Vercel',
        ];

        // Get NS records for the domain.
        $nsRecords = @dns_get_record($domain, DNS_NS);
        if (!$nsRecords) {
            // Try parent domain.
            $parts = explode('.', $domain);
            if (count($parts) > 2) {
                array_shift($parts);
                $parentDomain = implode('.', $parts);
                $nsRecords = @dns_get_record($parentDomain, DNS_NS);
            }
        }

        if ($nsRecords) {
            foreach ($nsRecords as $ns) {
                $target = strtolower($ns['target'] ?? '');
                foreach ($registrars as $pattern => $name) {
                    if (strpos($target, $pattern) !== false) {
                        return $name;
                    }
                }
            }
            // Not found in known list: extract domain from first NS record.
            $firstNs = strtolower($nsRecords[0]['target'] ?? '');
            $parts = explode('.', $firstNs);
            if (count($parts) >= 2) {
                return implode('.', array_slice($parts, -2));
            }
        }

        return null;
    }

    /**
     * Check DNS and output messages to the messenger.
     *
     * @param array $options Options: domain, ip_address, dkim_selector
     */
    public function checkDnsWithMessages(array $options = []): void
    {
        $results = $this->checkDns($options);

        if (empty($results['domain'])) {
            $this->messenger->addWarning(
                'Cannot check DNS records: no domain provided and sender email is not configured.' // @translate
            );
            return;
        }

        $domain = $results['domain'];
        $ipAddress = $results['ip_address'] ?? null;

        // Detect registrar/DNS provider for the domain.
        $registrar = $this->detectDomainRegistrar($domain);
        $registrarText = $registrar ?: 'unknown';

        if ($ipAddress) {
            $this->messenger->addNotice(new PsrMessage(
                'Checking DNS records for domain: {domain} (server IP: {ip})', // @translate
                ['domain' => $domain, 'ip' => $ipAddress]
            ));
        } else {
            $this->messenger->addNotice(new PsrMessage(
                'Checking DNS records for domain: {domain}', // @translate
                ['domain' => $domain]
            ));
        }

        // Collect actions needed for summary.
        $actionsNeeded = [];

        // SPF results.
        if ($results['spf']['valid']) {
            $this->messenger->addSuccess(new PsrMessage(
                'SPF: OK — {record}', // @translate
                ['record' => $results['spf']['record']]
            ));
        } else {
            $this->messenger->addWarning(new PsrMessage(
                'SPF: MISSING', // @translate
            ));
            $actionsNeeded[] = new PsrMessage(
                'SPF: Add TXT record at "{domain}" with value: {record}', // @translate
                [
                    'domain' => $domain,
                    'record' => $results['spf']['recommendation'],
                ]
            );
        }

        // DKIM results.
        if ($results['dkim']['valid']) {
            $this->messenger->addSuccess(new PsrMessage(
                'DKIM: OK (selector "{selector}") — {record}', // @translate
                [
                    'selector' => $results['dkim']['selector'],
                    'record' => $results['dkim']['record'],
                ]
            ));
        } else {
            $this->messenger->addWarning(new PsrMessage(
                'DKIM: MISSING (selector "{selector}")', // @translate
                ['selector' => $results['dkim']['selector']]
            ));

            // Generate DKIM key if requested.
            // Note: DKIM generation only makes sense if running your own mail server.
            // For SMTP relay (external mail provider), ask them for the DKIM public key.
            $generateDkim = !empty($options['generate_dkim']);
            if ($generateDkim && $this->canGenerateDkim()) {
                $dkimKey = $this->generateDkimKey($domain, $results['dkim']['selector']);
                if ($dkimKey) {
                    $dkimMessage = new PsrMessage(
                        'DKIM (own mail server): Add TXT record at "{dkim_domain}" with value:<br><pre>{record}</pre>Private key (configure in your mail server, e.g. OpenDKIM):<br><pre>{private_key}</pre>', // @translate
                        [
                            'dkim_domain' => $dkimKey['dkim_domain'],
                            'record' => htmlspecialchars($dkimKey['dns_record'], ENT_QUOTES, 'UTF-8'),
                            'private_key' => htmlspecialchars($dkimKey['private_key'], ENT_QUOTES, 'UTF-8'),
                        ]
                    );
                    $dkimMessage->setEscapeHtml(false);
                    $actionsNeeded[] = $dkimMessage;
                } else {
                    $actionsNeeded[] = new PsrMessage(
                        'DKIM: Failed to generate key. Add TXT record at "{dkim_domain}" with value: {record}', // @translate
                        [
                            'dkim_domain' => $results['dkim']['dkim_domain'],
                            'record' => $results['dkim']['recommendation'],
                        ]
                    );
                }
            } else {
                $actionsNeeded[] = new PsrMessage(
                    'DKIM: Add TXT record at "{dkim_domain}". If using SMTP relay, ask your mail provider for the public key. If running your own mail server, check the box to generate a key pair.', // @translate
                    [
                        'dkim_domain' => $results['dkim']['dkim_domain'],
                    ]
                );
            }
        }

        // DMARC results.
        if ($results['dmarc']['valid']) {
            $this->messenger->addSuccess(new PsrMessage(
                'DMARC: OK — {record}', // @translate
                ['record' => $results['dmarc']['record']]
            ));
        } else {
            $this->messenger->addWarning(new PsrMessage(
                'DMARC: MISSING', // @translate
            ));
            $actionsNeeded[] = new PsrMessage(
                'DMARC: Add TXT record at "{dmarc_domain}" with value: {record}', // @translate
                [
                    'dmarc_domain' => $results['dmarc']['dmarc_domain'],
                    'record' => $results['dmarc']['recommendation'],
                ]
            );
        }

        // PTR results.
        // Note: PTR is managed by the server/VPS provider (who owns the IP),
        // not the domain registrar.
        $ptrHasIssue = false;
        if (!empty($results['ptr']['ip'])) {
            $ip = $results['ptr']['ip'];
            $provider = $results['ptr']['provider'] ?? null;
            $providerText = $provider ?: 'unknown';

            if ($results['ptr']['valid']) {
                if ($results['ptr']['matches_domain']) {
                    $this->messenger->addSuccess(new PsrMessage(
                        'PTR: OK — {ip} resolves to {hostname}', // @translate
                        [
                            'ip' => $ip,
                            'hostname' => $results['ptr']['hostname'],
                        ]
                    ));
                } else {
                    $ptrHasIssue = true;
                    $this->messenger->addWarning(new PsrMessage(
                        'PTR: MISMATCH — {ip} resolves to {hostname} (does not match {domain})', // @translate
                        [
                            'ip' => $ip,
                            'hostname' => $results['ptr']['hostname'],
                            'domain' => $domain,
                        ]
                    ));
                    $actionsNeeded[] = new PsrMessage(
                        'PTR (reverse DNS): Set reverse DNS for IP {ip} to "mail.{domain}" in your server/VPS provider panel ({provider}). Current value "{hostname}" causes email rejection.', // @translate
                        [
                            'ip' => $ip,
                            'domain' => $domain,
                            'provider' => $providerText,
                            'hostname' => $results['ptr']['hostname'],
                        ]
                    );
                    $actionsNeeded[] = new PsrMessage(
                        'Important: First create an A record for "mail.{domain}" pointing to {ip} at your domain registrar ({registrar}), then wait for DNS propagation (up to 24h) before configuring the PTR.', // @translate
                        [
                            'domain' => $domain,
                            'ip' => $ip,
                            'registrar' => $registrarText,
                        ]
                    );
                }
            } else {
                $ptrHasIssue = true;
                $this->messenger->addWarning(new PsrMessage(
                    'PTR: NOT CONFIGURED for {ip}', // @translate
                    ['ip' => $ip]
                ));
                $actionsNeeded[] = new PsrMessage(
                    'PTR (reverse DNS): Set reverse DNS for IP {ip} to "mail.{domain}" in your server/VPS provider panel ({provider}).', // @translate
                    [
                        'ip' => $ip,
                        'domain' => $domain,
                        'provider' => $providerText,
                    ]
                );
                $actionsNeeded[] = new PsrMessage(
                    'Important: First create an A record for "mail.{domain}" pointing to {ip} at your domain registrar ({registrar}), then wait for DNS propagation (up to 24h) before configuring the PTR.', // @translate
                    [
                        'domain' => $domain,
                        'ip' => $ip,
                        'registrar' => $registrarText,
                    ]
                );
            }
        }

        // Summary with actions.
        if (empty($actionsNeeded)) {
            $this->messenger->addSuccess(
                'All DNS records (SPF, DKIM, DMARC, PTR) are properly configured!' // @translate
            );
        } else {
            $actionsList = '<ul><li>' . implode('</li><li>', array_map('strval', $actionsNeeded)) . '</li></ul>';
            $message = new PsrMessage(
                'Actions needed to improve email deliverability:{actions}', // @translate
                ['actions' => $actionsList]
            );
            $message->setEscapeHtml(false);
            $this->messenger->addNotice($message);
        }

        // Recommend SMTP relay when any DNS issue exists.
        // SMTP relay through an existing mail server is often easier than configuring
        // all DNS records (SPF, DKIM, DMARC, PTR) on a cloud server.
        $hasDnsIssue = !$results['spf']['valid']
            || !$results['dkim']['valid']
            || !$results['dmarc']['valid']
            || $ptrHasIssue;

        if ($hasDnsIssue) {
            // Detect mail host from MX record.
            $mailHost = $this->detectMailHost($domain);
            $mailProvider = $this->detectMailProvider($mailHost);

            // Check if current config already uses the same SMTP server.
            $currentTransport = $this->getTransportType();
            $alreadyConfigured = false;
            if ($currentTransport === 'smtp') {
                $currentHost = $this->getSmtpHost();
                if ($currentHost) {
                    // Compare IPs to handle aliases (mail.galae.net vs smtp.galae.net).
                    $currentIp = @gethostbyname($currentHost);
                    $detectedIp = @gethostbyname($mailHost);
                    $alreadyConfigured = $currentIp && $detectedIp
                        && $currentIp !== $currentHost
                        && $currentIp === $detectedIp;
                }
            }

            if (!$alreadyConfigured) {
                // Use admin email as username (it's a real account that exists).
                $adminEmail = $this->settings->get('administrator_email') ?? 'admin@' . $domain;

                $smtpConfig = <<<CONFIG
'mail' => [
    'transport' => [
        'type' => 'smtp',
        'options' => [
            'host' => '$mailHost',
            'port' => 587,
            'connection_class' => 'login',
            'connection_config' => [
                'ssl' => 'tls',
                'username' => '$adminEmail',
                'password' => 'your-password',
            ],
        ],
    ],
],
CONFIG;
                // Get provider info for the message.
                $providerText = $results['ptr']['provider'] ?? null;
                $providerInfo = $providerText ? " (server provider: $providerText)" : '';
                $mailProviderInfo = $mailProvider ? " (mail provider: $mailProvider)" : '';

                $smtpMessage = new PsrMessage(
                    'Alternative: Use SMTP relay through your mail server{mail_provider_info}. This avoids DNS configuration issues on cloud servers{provider_info}. Add the following to config/local.config.php:<br><pre>{config}</pre>', // @translate
                    [
                        'mail_provider_info' => $mailProviderInfo,
                        'provider_info' => $providerInfo,
                        'config' => htmlspecialchars($smtpConfig, ENT_QUOTES, 'UTF-8'),
                    ]
                );
                $smtpMessage->setEscapeHtml(false);
                $this->messenger->addNotice($smtpMessage);
            }
        }
    }

    /**
     * Get TXT records from DNS for a domain.
     */
    protected function getDnsTxtRecords(string $domain): array
    {
        $records = [];
        try {
            $dnsRecords = @dns_get_record($domain, DNS_TXT);
            if ($dnsRecords) {
                foreach ($dnsRecords as $record) {
                    if (!empty($record['txt'])) {
                        $records[] = $record['txt'];
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail - DNS lookup may not be available.
        }
        return $records;
    }

    /**
     * Detect mail host from MX record.
     *
     * Converts incoming MX host (smtpin-*) to outgoing SMTP host (smtpout-* or mail.*).
     */
    protected function detectMailHost(string $domain): string
    {
        // Get MX records.
        $mxRecords = @dns_get_record($domain, DNS_MX);
        if (!$mxRecords) {
            // Try parent domain for subdomains.
            $parts = explode('.', $domain);
            if (count($parts) > 2) {
                array_shift($parts);
                $parentDomain = implode('.', $parts);
                $mxRecords = @dns_get_record($parentDomain, DNS_MX);
            }
        }

        if ($mxRecords) {
            // Sort by priority (lowest first).
            usort($mxRecords, fn($a, $b) => ($a['pri'] ?? 99) <=> ($b['pri'] ?? 99));
            $mxHost = rtrim($mxRecords[0]['target'] ?? '', '.');

            if ($mxHost) {
                // Extract mail domain from MX host.
                // e.g., "smtpin-01.galae.net" -> "galae.net"
                $parts = explode('.', $mxHost);
                if (count($parts) >= 2) {
                    $mailDomain = implode('.', array_slice($parts, -2));

                    // Try common SMTP hostnames for outgoing mail.
                    $smtpHosts = [
                        'smtp.' . $mailDomain,
                        'mail.' . $mailDomain,
                        'smtpout.' . $mailDomain,
                        str_replace('smtpin', 'smtpout', $mxHost),
                    ];

                    // Return first one that resolves.
                    foreach ($smtpHosts as $host) {
                        $ip = @gethostbyname($host);
                        if ($ip && $ip !== $host) {
                            return $host;
                        }
                    }

                    // Fallback to mail.{mailDomain}.
                    return 'mail.' . $mailDomain;
                }

                return $mxHost;
            }
        }

        // Fallback to mail.{domain}.
        return 'mail.' . $domain;
    }

    /**
     * Detect mail provider from mail host.
     */
    protected function detectMailProvider(string $mailHost): ?string
    {
        $providers = [
            'galae.net' => 'Galae',
            'gandi.net' => 'Gandi',
            'google.com' => 'Google Workspace',
            'googlemail.com' => 'Google Workspace',
            'infomaniak.ch' => 'Infomaniak',
            'ionos.com' => 'IONOS',
            'mailchimp.com' => 'Mailchimp',
            'mailgun.org' => 'Mailgun',
            'n0c.com' => 'PlanetHoster',
            'outlook.com' => 'Microsoft 365',
            'ovh.com' => 'OVH',
            'ovh.net' => 'OVH',
            'planethoster.net' => 'PlanetHoster',
            'protection.outlook.com' => 'Microsoft 365',
            'protonmail.ch' => 'ProtonMail',
            'sendgrid.net' => 'SendGrid',
            'zoho.com' => 'Zoho',
        ];

        $mailHost = strtolower($mailHost);
        foreach ($providers as $pattern => $name) {
            if (strpos($mailHost, $pattern) !== false) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Generate DKIM key pair.
     *
     * @param string $domain The domain name
     * @param string $selector The DKIM selector (default: "mail")
     * @return array|null Array with keys or null on failure
     */
    public function generateDkimKey(string $domain, string $selector = 'mail'): ?array
    {
        $canExec = function_exists('exec')
            && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));

        $privateKey = null;
        $publicKey = null;

        if ($canExec) {
            $privateKeyPath = tempnam(sys_get_temp_dir(), 'dkim_priv_');
            $publicKeyPath = tempnam(sys_get_temp_dir(), 'dkim_pub_');

            exec("openssl genrsa -out $privateKeyPath 1024 2>&1", $output, $returnVar);
            if ($returnVar !== 0) {
                @unlink($privateKeyPath);
                @unlink($publicKeyPath);
                return null;
            }

            exec("openssl rsa -in $privateKeyPath -pubout -out $publicKeyPath 2>&1", $output, $returnVar);
            if ($returnVar !== 0) {
                @unlink($privateKeyPath);
                @unlink($publicKeyPath);
                return null;
            }

            $privateKey = file_get_contents($privateKeyPath);
            $publicKey = file_get_contents($publicKeyPath);

            @unlink($privateKeyPath);
            @unlink($publicKeyPath);
        } elseif (extension_loaded('openssl')) {
            $config = [
                'private_key_bits' => 1024,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ];
            $res = openssl_pkey_new($config);
            if (!$res) {
                return null;
            }

            openssl_pkey_export($res, $privateKey);
            $details = openssl_pkey_get_details($res);
            $publicKey = $details['key'] ?? null;
        } else {
            return null;
        }

        if (!$privateKey || !$publicKey) {
            return null;
        }

        $publicKeyClean = trim(str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
            '',
            $publicKey
        ));

        $dkimDomain = "$selector._domainkey.$domain";

        return [
            'private_key' => $privateKey,
            'public_key_clean' => $publicKeyClean,
            'selector' => $selector,
            'dkim_domain' => $dkimDomain,
            'dns_record' => "v=DKIM1; k=rsa; p=$publicKeyClean",
        ];
    }

    /**
     * Check if DKIM key generation is available.
     */
    public function canGenerateDkim(): bool
    {
        $canExec = function_exists('exec')
            && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));

        return $canExec || extension_loaded('openssl');
    }
}

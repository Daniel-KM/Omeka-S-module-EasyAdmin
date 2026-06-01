<?php declare(strict_types=1);

namespace EasyAdmin\Service;

use Laminas\Http\Client;
use Laminas\Http\Client\Adapter\Curl;
use Laminas\Http\Client\Adapter\Socket;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class HttpClientFactory implements FactoryInterface
{
    /**
     * Create an HTTP Client instance.
     *
     * When no adapter is explicitly configured, prefer the Curl adapter with
     * HTTP/2 negotiation (TLS-ALPN, transparent fallback to HTTP/1.1) if the
     * curl extension supports it. Otherwise fall back to the Socket adapter.
     *
     * Override this default by setting `http_client.adapter` and optionally
     * `http_client.curloptions` in local.config.php.
     *
     * @return \Laminas\Http\Client
     */
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, ?array $options = null)
    {
        $config = $serviceLocator->get('Config');
        $options = [];
        if (isset($config['http_client']) && is_array($config['http_client'])) {
            $options = $config['http_client'];
        }

        // Pick the Curl adapter by default when curl supports HTTP/2.
        if (empty($options['adapter'])) {
            $options['adapter'] = extension_loaded('curl')
                && defined('CURL_HTTP_VERSION_2TLS')
                ? Curl::class
                : Socket::class;
        }

        // Client::setOptions() does not forward curloptions to the adapter;
        // hand them over directly after instantiation.
        $curlOptions = $options['curloptions'] ?? [];
        unset($options['curloptions']);

        $client = new Client(null, $options);

        // When the active adapter is Curl, default to HTTP/2 negotiation
        // (TLS-ALPN, transparent fallback to HTTP/1.1) unless the admin has
        // already pinned a CURLOPT_HTTP_VERSION value.
        if ($options['adapter'] === Curl::class
            && defined('CURL_HTTP_VERSION_2TLS')
            && !array_key_exists(CURLOPT_HTTP_VERSION, $curlOptions)
        ) {
            $curlOptions[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }
        if ($curlOptions) {
            $adapter = $client->getAdapter();
            if ($adapter instanceof Curl) {
                $adapter->setOptions(['curloptions' => $curlOptions]);
            }
        }

        return $client;
    }
}

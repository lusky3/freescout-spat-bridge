<?php

namespace Modules\SpatBridge\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Signed HTTP client for the SPAT WordPress plugin suite. Never throws:
 * every failure comes back as a ClientResult with an error code.
 */
class SpatClient
{
    private const PATH_PING = '/wp-json/spat/v1/freescout/ping';
    private const PATH_CUSTOMER = '/wp-json/spat/v1/freescout/customer';
    private const TIMEOUT = 3;
    private const CONNECT_TIMEOUT = 2;

    /** @var ClientInterface */
    private $http;
    /** @var OptionStoreInterface */
    private $options;
    /** @var LoggerInterface */
    private $logger;
    /** @var callable */
    private $clock;

    public function __construct(
        ClientInterface $http,
        OptionStoreInterface $options,
        LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->http = $http;
        $this->options = $options;
        $this->logger = $logger;
        $this->clock = $clock ?: 'time';
    }

    public function ping(): ClientResult
    {
        return $this->post(self::PATH_PING, '{}');
    }

    public function customer(string $email): ClientResult
    {
        return $this->post(self::PATH_CUSTOMER, (string) json_encode(['email' => $email]));
    }

    private function post(string $path, string $body): ClientResult
    {
        $baseUrl = trim((string) $this->options->get('spatbridge.wp_base_url', ''));
        $secret = (string) $this->options->get('spatbridge.shared_secret', '');
        if ($baseUrl === '' || $secret === '') {
            return ClientResult::failure('not_configured');
        }

        $started = microtime(true);

        try {
            $timestamp = (string) call_user_func($this->clock);
            $response = $this->http->request('POST', rtrim($baseUrl, '/') . $path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    Signer::HEADER_TIMESTAMP => $timestamp,
                    Signer::HEADER_SIGNATURE => Signer::sign($timestamp, $body, $secret),
                ],
                'body' => $body,
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'http_errors' => false,
            ]);
            $status = (int) $response->getStatusCode();
            $decoded = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            $code = $this->isTimeout($e) ? 'timeout' : 'network';
            $this->logger->warning(
                '[SpatBridge] request to ' . $path . ' failed: ' . $code . ' (' . $e->getMessage() . ')'
            );
            return ClientResult::failure($code, 0, $this->elapsedMs($started));
        }

        $latency = $this->elapsedMs($started);

        if ($status === 200) {
            if (!is_array($decoded)) {
                $this->logger->warning('[SpatBridge] non-JSON 200 from ' . $path);
                return ClientResult::failure('bad_response', $status, $latency);
            }
            return ClientResult::success($status, $decoded, $latency);
        }

        $wpCode = is_array($decoded) && isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : '';
        $this->logger->warning('[SpatBridge] ' . $path . ' returned HTTP ' . $status . ' ' . $wpCode);

        return ClientResult::failure($this->mapError($status, $wpCode), $status, $latency);
    }

    private function mapError(int $status, string $wpCode): string
    {
        if ($status === 403) {
            return 'bad_signature';
        }
        if ($status === 503 && $wpCode === 'spat_freescout_not_configured') {
            return 'wp_not_configured';
        }
        if ($status === 429) {
            return 'rate_limited';
        }
        return 'http_error';
    }

    private function isTimeout(\Throwable $e): bool
    {
        if (!$e instanceof ConnectException) {
            return false;
        }
        $message = $e->getMessage();
        return stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false;
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}

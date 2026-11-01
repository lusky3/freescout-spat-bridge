<?php

namespace Modules\SpatBridge\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Signed HTTP client for the SPAT WordPress plugin suite. Never throws:
 * every failure comes back as a ClientResult with an error code.
 *
 * Failures are logged at error, because FreeScout's default APP_LOG_LEVEL
 * (error) drops warnings: a wrong secret, a skewed clock, an outage or an
 * outdated SPAT would otherwise leave nothing in the log to explain why
 * the sidebar said "League info unavailable". Only answers WordPress gives
 * on purpose (rate limiting, and an action it refused with a reason) stay
 * at warning. The module's own missing URL or secret is not logged: the
 * settings page shows it, and every page view would repeat it.
 */
class SpatClient
{
    private const PATH_PING = '/wp-json/spat/v1/freescout/ping';
    private const PATH_CUSTOMER = '/wp-json/spat/v1/freescout/customer';
    private const PATH_ACTION = '/wp-json/spat/v1/freescout/action';
    private const PATH_QUERY = '/wp-json/spat/v1/freescout/query';
    private const TIMEOUT = 3;
    private const ACTION_TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 2;
    /** Error codes that are WordPress answering as designed, not a fault. */
    private const EXPECTED_ERRORS = [
        'rate_limited',
        'actor_unknown',
        'actor_forbidden',
        'unknown_action',
        'action_error',
    ];

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

    /**
     * Everything WordPress has for a customer, looked up by every address
     * in the list (CustomerEmails::of(): normalized, primary first, at most
     * CustomerEmails::MAX). WordPress refuses an empty or longer list.
     *
     * @param string[] $emails
     */
    public function customer(array $emails): ClientResult
    {
        $result = $this->post(self::PATH_CUSTOMER, $this->encode(['emails' => array_values($emails)]));

        $data = $result->ok() ? $result->data() : null;
        $ignored = is_array($data) && isset($data['ignored']) && is_array($data['ignored']) ? $data['ignored'] : [];
        if ($ignored !== []) {
            // WordPress skips a later address it finds invalid and looks up
            // the rest; worth a trace, not a failure.
            $this->logger->info('[SpatBridge] WordPress skipped customer address(es) at index '
                . implode(', ', array_map('strval', array_filter($ignored, 'is_scalar'))) . ' as invalid');
        }

        return $result;
    }

    /**
     * Runs (or, with $dryRun, previews) a feature action in WordPress as the
     * WordPress user with the agent's email. WordPress replays a completed
     * non-dry run with the same idempotency key instead of running it again.
     */
    public function action(
        string $feature,
        string $action,
        array $params,
        bool $dryRun,
        string $idempotencyKey,
        string $actorEmail,
        string $actorName
    ): ClientResult {
        return $this->post(self::PATH_ACTION, $this->encode([
            'feature' => $feature,
            'action' => $action,
            'params' => $params === [] ? new \stdClass() : $params,
            'dry_run' => $dryRun,
            'idempotency_key' => $idempotencyKey,
            'actor' => ['email' => $actorEmail, 'name' => $actorName],
        ]), self::ACTION_TIMEOUT, true);
    }

    /**
     * A read-only lookup a feature's pop-up needs, e.g. searching transfers.
     */
    public function query(string $feature, string $query, array $params): ClientResult
    {
        return $this->post(self::PATH_QUERY, $this->encode([
            'feature' => $feature,
            'query' => $query,
            'params' => $params === [] ? new \stdClass() : $params,
        ]), self::ACTION_TIMEOUT, true);
    }

    private function encode(array $body): string
    {
        return (string) json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param bool $actionErrors Map WordPress action errors (actor_unknown,
     *        actor_forbidden, unknown_action, action_error) instead of
     *        reporting them as a plain http_error.
     */
    private function post(
        string $path,
        string $body,
        int $timeout = self::TIMEOUT,
        bool $actionErrors = false
    ): ClientResult {
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
                'timeout' => $timeout,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'http_errors' => false,
                // A redirect would turn the signed POST into a GET (301/302) or
                // send the secret's signature elsewhere; report it instead.
                'allow_redirects' => false,
            ]);
            $status = (int) $response->getStatusCode();
            $location = $response->getHeaderLine('Location');
            $decoded = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            $code = $this->isTimeout($e) ? 'timeout' : 'network';
            $this->logFailure($code, 'request to ' . $path . ' failed: ' . $code . ' (' . $e->getMessage() . ')');
            return ClientResult::failure($code, 0, $this->elapsedMs($started));
        }

        $latency = $this->elapsedMs($started);

        if ($status === 200) {
            if (!is_array($decoded)) {
                $this->logFailure('bad_response', 'non-JSON 200 from ' . $path . ': bad_response');
                return ClientResult::failure('bad_response', $status, $latency);
            }
            return ClientResult::success($status, $decoded, $latency);
        }

        if ($status >= 300 && $status < 400) {
            $this->logFailure('redirected', $path . ' redirected (HTTP ' . $status . ') to ' . $location);
            return ClientResult::failure('redirected', $status, $latency, $this->siteUrlFrom($location, $path));
        }

        $wpCode = is_array($decoded) && isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : '';
        $code = $this->mapError($status, $wpCode);
        $message = '';
        if ($actionErrors && $code === 'http_error') {
            $message = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
                ? trim($decoded['message'])
                : '';
            $code = $this->mapActionError($wpCode, $message);
        }
        $this->logFailure($code, $path . ' returned HTTP ' . $status . ' ' . $wpCode . ': ' . $code);

        return $code === 'http_error'
            ? ClientResult::failure($code, $status, $latency)
            : ClientResult::failure($code, $status, $latency, $message);
    }

    private function logFailure(string $code, string $message): void
    {
        $level = in_array($code, self::EXPECTED_ERRORS, true) ? LogLevel::WARNING : LogLevel::ERROR;
        $this->logger->log($level, '[SpatBridge] ' . $message);
    }

    /**
     * Refines an http_error from the action or query route. Any error that
     * carries a WordPress message becomes action_error, so the pop-up can
     * show that message as is.
     */
    private function mapActionError(string $wpCode, string $message): string
    {
        switch ($wpCode) {
            case 'spat_freescout_actor_unknown':
                return 'actor_unknown';
            case 'spat_freescout_actor_forbidden':
                return 'actor_forbidden';
            case 'spat_freescout_unknown_action':
            case 'spat_freescout_unknown_query':
                return 'unknown_action';
        }

        return $message === '' ? 'http_error' : 'action_error';
    }

    private function mapError(int $status, string $wpCode): string
    {
        if ($status === 403) {
            if ($wpCode === 'spat_freescout_request_expired') {
                return 'clock_skew';
            }
            if ($wpCode === 'spat_freescout_invalid_signature' || $wpCode === 'spat_freescout_missing_timestamp') {
                return 'bad_signature';
            }
            return 'http_error';
        }
        if ($status === 503 && $wpCode === 'spat_freescout_not_configured') {
            return 'wp_not_configured';
        }
        if ($status === 429) {
            return 'rate_limited';
        }
        if ($status === 404 && $wpCode === 'rest_no_route') {
            return 'spat_outdated';
        }
        return 'http_error';
    }

    /**
     * The redirect target with the route path stripped, i.e. the site URL the
     * module should be configured with.
     */
    private function siteUrlFrom(string $location, string $path): string
    {
        $location = trim($location);
        if ($location !== '' && substr($location, -strlen($path)) === $path) {
            return substr($location, 0, -strlen($path));
        }

        return $location;
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

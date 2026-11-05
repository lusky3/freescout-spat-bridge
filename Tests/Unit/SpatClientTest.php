<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\SpatBridge\Services\Signer;
use Modules\SpatBridge\Services\SpatClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Support\FakeOptionStore;

class SpatClientTest extends TestCase
{
    private const BASE = 'https://wp.example';
    private const SECRET = 'a-shared-secret-of-at-least-32-chars';
    private const NOW = 1700000000;

    /** @var array<int, array<string, mixed>> */
    private $history = [];

    /** @var AbstractLogger&object{records: array} */
    private $logger;

    private function client(array $responses, string $base = self::BASE, string $secret = self::SECRET): SpatClient
    {
        $this->logger = new class extends AbstractLogger {
            public $records = [];

            public function log($level, $message, array $context = [])
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $options = new FakeOptionStore();
        $options->set('spatbridge.wp_base_url', $base);
        $options->set('spatbridge.shared_secret', $secret);

        return new SpatClient(new Client(['handler' => $stack]), $options, $this->logger, function () {
            return self::NOW;
        });
    }

    private function errorResponse(int $status, string $code): Response
    {
        $body = ['code' => $code, 'message' => 'x', 'data' => ['status' => $status]];

        return new Response($status, [], json_encode($body));
    }

    public function test_customer_sends_the_address_list_signed_to_the_customer_route(): void
    {
        $client = $this->client([new Response(200, [], '{}')], self::BASE . '/');
        $client->customer(['a@example.com', 'b@example.com']);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $body = '{"emails":["a@example.com","b@example.com"]}';

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://wp.example/wp-json/spat/v1/freescout/customer', (string) $request->getUri());
        $this->assertSame((string) self::NOW, $request->getHeaderLine(Signer::HEADER_TIMESTAMP));
        $this->assertSame(
            Signer::sign((string) self::NOW, $body, self::SECRET),
            $request->getHeaderLine(Signer::HEADER_SIGNATURE)
        );
        $this->assertSame($body, (string) $request->getBody());
        $this->assertSame(3, $this->history[0]['options']['timeout']);
        $this->assertSame(2, $this->history[0]['options']['connect_timeout']);
    }

    public function test_ping_posts_an_empty_object_to_the_ping_route(): void
    {
        $client = $this->client([new Response(200, [], '{"spat_version":"1.1.3"}')]);
        $result = $client->ping();

        $request = $this->history[0]['request'];
        $this->assertSame('https://wp.example/wp-json/spat/v1/freescout/ping', (string) $request->getUri());
        $this->assertSame('{}', (string) $request->getBody());
        $this->assertTrue($result->ok());
    }

    public function test_ok_result_carries_decoded_data_on_200(): void
    {
        $client = $this->client([new Response(200, [], '{"emails":["a@example.com"],"player_ids":[1,4]}')]);
        $result = $client->customer(['a@example.com']);

        $this->assertTrue($result->ok());
        $this->assertSame(200, $result->status());
        $this->assertSame('', $result->errorCode());
        $this->assertSame(['emails' => ['a@example.com'], 'player_ids' => [1, 4]], $result->data());
        $this->assertGreaterThanOrEqual(0, $result->latencyMs());
    }

    /**
     * @dataProvider wpErrorProvider
     */
    public function test_maps_wp_error_codes(Response $response, int $status, string $expected): void
    {
        $result = $this->client([$response])->customer(['a@example.com']);

        $this->assertFalse($result->ok());
        $this->assertSame($status, $result->status());
        $this->assertSame($expected, $result->errorCode());
    }

    public function wpErrorProvider(): array
    {
        return [
            'invalid signature' => [
                $this->errorResponse(403, 'spat_freescout_invalid_signature'), 403, 'bad_signature',
            ],
            'missing timestamp' => [
                $this->errorResponse(403, 'spat_freescout_missing_timestamp'), 403, 'bad_signature',
            ],
            'request expired' => [$this->errorResponse(403, 'spat_freescout_request_expired'), 403, 'clock_skew'],
            'non-spat 403 html' => [new Response(403, [], '<html>Forbidden by WAF</html>'), 403, 'http_error'],
            'non-spat 403 json' => [$this->errorResponse(403, 'rest_forbidden'), 403, 'http_error'],
            'not configured on wp' => [
                $this->errorResponse(503, 'spat_freescout_not_configured'), 503, 'wp_not_configured',
            ],
            'rate limited' => [$this->errorResponse(429, 'spat_freescout_rate_limited'), 429, 'rate_limited'],
            'server error' => [new Response(500, [], 'boom'), 500, 'http_error'],
            'spat without the routes' => [$this->errorResponse(404, 'rest_no_route'), 404, 'spat_outdated'],
            'plain 404 page' => [new Response(404, [], '<html>Not Found</html>'), 404, 'http_error'],
        ];
    }

    public function test_non_json_200_is_bad_response(): void
    {
        $result = $this->client([new Response(200, [], '<html>not json</html>')])->customer(['a@example.com']);

        $this->assertFalse($result->ok());
        $this->assertSame(200, $result->status());
        $this->assertSame('bad_response', $result->errorCode());
        $this->assertNull($result->data());
    }

    public function test_connect_exception_is_network_and_never_throws(): void
    {
        $result = $this->client([
            new ConnectException('Connection refused', new Request('POST', self::BASE)),
        ])->customer(['a@example.com']);

        $this->assertFalse($result->ok());
        $this->assertSame(0, $result->status());
        $this->assertSame('network', $result->errorCode());
    }

    public function test_timeout_connect_exception_is_timeout(): void
    {
        $result = $this->client([
            new ConnectException(
                'cURL error 28: Operation timed out after 3000 milliseconds',
                new Request('POST', self::BASE)
            ),
        ])->customer(['a@example.com']);

        $this->assertSame('timeout', $result->errorCode());
    }

    public function test_unexpected_exception_is_network(): void
    {
        $result = $this->client([new \RuntimeException('weird')])->customer(['a@example.com']);

        $this->assertSame('network', $result->errorCode());
    }

    public function test_missing_url_or_secret_is_not_configured_without_http_call(): void
    {
        $noUrl = $this->client([], '');
        $this->assertSame('not_configured', $noUrl->customer(['a@example.com'])->errorCode());
        $this->assertCount(0, $this->history);

        $noSecret = $this->client([], self::BASE, '');
        $result = $noSecret->ping();
        $this->assertFalse($result->ok());
        $this->assertSame('not_configured', $result->errorCode());
        $this->assertSame(0, $result->status());
        $this->assertSame(0, $result->latencyMs());
        $this->assertCount(0, $this->history);
    }

    public function test_redirect_is_reported_not_followed(): void
    {
        $result = $this->client([
            new Response(301, ['Location' => 'https://www.wp.example/wp-json/spat/v1/freescout/ping']),
            new Response(200, [], '{"spat_version":"1.1.3"}'),
        ])->ping();

        $this->assertCount(1, $this->history);
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
        $this->assertFalse($result->ok());
        $this->assertSame(301, $result->status());
        $this->assertSame('redirected', $result->errorCode());
        $this->assertSame('https://www.wp.example', $result->detail());
    }

    public function test_redirect_elsewhere_keeps_the_whole_location(): void
    {
        $result = $this->client([
            new Response(302, ['Location' => 'https://login.example/sso']),
        ])->customer(['a@example.com']);

        $this->assertSame('redirected', $result->errorCode());
        $this->assertSame(302, $result->status());
        $this->assertSame('https://login.example/sso', $result->detail());
    }

    public function test_the_list_is_sent_as_a_json_list_whatever_its_keys(): void
    {
        $this->client([new Response(200, [], '{}')])->customer([3 => 'a@example.com', 7 => 'b@example.com']);

        $this->assertSame(
            '{"emails":["a@example.com","b@example.com"]}',
            (string) $this->history[0]['request']->getBody()
        );
    }

    public function test_addresses_wordpress_skipped_are_noted_at_info_and_the_lookup_still_succeeds(): void
    {
        $body = '{"emails":["a@example.com"],"player_ids":[],"sections":{},"missing":{},"ignored":[1,3]}';
        $result = $this->client([new Response(200, [], $body)])
            ->customer(['a@example.com', 'bad', 'c@example.com', 'worse']);

        $this->assertTrue($result->ok());
        $this->assertSame([1, 3], $result->data()['ignored']);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('info', $this->logger->records[0][0]);
        $this->assertStringContainsString('index 1, 3', $this->logger->records[0][1]);
    }

    public function test_no_or_an_empty_ignored_list_logs_nothing(): void
    {
        foreach (['{"emails":["a@example.com"]}', '{"emails":["a@example.com"],"ignored":[]}'] as $body) {
            $result = $this->client([new Response(200, [], $body)])->customer(['a@example.com']);

            $this->assertTrue($result->ok());
            $this->assertSame([], $this->logger->records, $body);
        }
    }

    /**
     * FreeScout's default log level is error, so failures an operator must
     * fix (or an outage they must be able to explain later) are logged at
     * error. Answers WordPress gives on purpose stay at warning.
     *
     * @dataProvider logLevelProvider
     * @param mixed $answer A response or an exception for the mock handler.
     */
    public function test_failures_are_logged_at_a_level_an_operator_sees($answer, string $level): void
    {
        $this->client([$answer])->customer(['a@example.com']);

        $this->assertCount(1, $this->logger->records);
        $this->assertSame($level, $this->logger->records[0][0]);
        $this->assertStringContainsString('/wp-json/spat/v1/freescout/customer', $this->logger->records[0][1]);
    }

    public function logLevelProvider(): array
    {
        $request = new Request('POST', self::BASE);

        return [
            'bad signature' => [$this->errorResponse(403, 'spat_freescout_invalid_signature'), 'error'],
            'clock skew' => [$this->errorResponse(403, 'spat_freescout_request_expired'), 'error'],
            'not configured in WordPress' => [$this->errorResponse(503, 'spat_freescout_not_configured'), 'error'],
            'SPAT without the routes' => [$this->errorResponse(404, 'rest_no_route'), 'error'],
            'request refused' => [$this->errorResponse(400, 'spat_freescout_bad_request'), 'error'],
            'server error' => [new Response(500, [], 'boom'), 'error'],
            'redirect' => [new Response(301, ['Location' => 'https://elsewhere.example/']), 'error'],
            'non-JSON 200' => [new Response(200, [], '<html>'), 'error'],
            'unreachable' => [new ConnectException('Connection refused', $request), 'error'],
            'timeout' => [new ConnectException('cURL error 28: Operation timed out', $request), 'error'],
            'rate limited' => [$this->errorResponse(429, 'spat_freescout_rate_limited'), 'warning'],
        ];
    }

    public function test_a_success_logs_nothing(): void
    {
        $this->client([new Response(200, [], '{}')])->customer(['a@example.com']);

        $this->assertSame([], $this->logger->records);
    }

    public function test_the_modules_own_missing_settings_log_nothing(): void
    {
        // Shown on the settings page; logging it on every page view adds nothing.
        $this->client([], '')->customer(['a@example.com']);

        $this->assertSame([], $this->logger->records);
    }

    public function test_the_logged_line_never_carries_the_secret_or_the_addresses(): void
    {
        $this->client([$this->errorResponse(403, 'spat_freescout_invalid_signature')])
            ->customer(['private@example.com']);

        $this->assertStringNotContainsString(self::SECRET, $this->logger->records[0][1]);
        $this->assertStringNotContainsString('private@example.com', $this->logger->records[0][1]);
        $this->assertStringContainsString('bad_signature', $this->logger->records[0][1]);
    }
}

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
use Psr\Log\NullLogger;
use Tests\Support\FakeOptionStore;

class SpatClientTest extends TestCase
{
    private const BASE = 'https://wp.example';
    private const SECRET = 'a-shared-secret-of-at-least-32-chars';
    private const NOW = 1700000000;

    /** @var array<int, array<string, mixed>> */
    private $history = [];

    private function client(array $responses, string $base = self::BASE, string $secret = self::SECRET): SpatClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $options = new FakeOptionStore();
        $options->set('spatbridge.wp_base_url', $base);
        $options->set('spatbridge.shared_secret', $secret);

        return new SpatClient(new Client(['handler' => $stack]), $options, new NullLogger(), function () {
            return self::NOW;
        });
    }

    private function errorResponse(int $status, string $code): Response
    {
        $body = ['code' => $code, 'message' => 'x', 'data' => ['status' => $status]];

        return new Response($status, [], json_encode($body));
    }

    public function test_customer_sends_signed_json_to_the_customer_route(): void
    {
        $client = $this->client([new Response(200, [], '{}')], self::BASE . '/');
        $client->customer('a@example.com');

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $body = '{"email":"a@example.com"}';

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
        $client = $this->client([new Response(200, [], '{"email":"a@example.com","player_ids":[1]}')]);
        $result = $client->customer('a@example.com');

        $this->assertTrue($result->ok());
        $this->assertSame(200, $result->status());
        $this->assertSame('', $result->errorCode());
        $this->assertSame(['email' => 'a@example.com', 'player_ids' => [1]], $result->data());
        $this->assertGreaterThanOrEqual(0, $result->latencyMs());
    }

    /**
     * @dataProvider wpErrorProvider
     */
    public function test_maps_wp_error_codes(Response $response, int $status, string $expected): void
    {
        $result = $this->client([$response])->customer('a@example.com');

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
        ];
    }

    public function test_non_json_200_is_bad_response(): void
    {
        $result = $this->client([new Response(200, [], '<html>not json</html>')])->customer('a@example.com');

        $this->assertFalse($result->ok());
        $this->assertSame(200, $result->status());
        $this->assertSame('bad_response', $result->errorCode());
        $this->assertNull($result->data());
    }

    public function test_connect_exception_is_network_and_never_throws(): void
    {
        $result = $this->client([
            new ConnectException('Connection refused', new Request('POST', self::BASE)),
        ])->customer('a@example.com');

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
        ])->customer('a@example.com');

        $this->assertSame('timeout', $result->errorCode());
    }

    public function test_unexpected_exception_is_network(): void
    {
        $result = $this->client([new \RuntimeException('weird')])->customer('a@example.com');

        $this->assertSame('network', $result->errorCode());
    }

    public function test_missing_url_or_secret_is_not_configured_without_http_call(): void
    {
        $noUrl = $this->client([], '');
        $this->assertSame('not_configured', $noUrl->customer('a@example.com')->errorCode());
        $this->assertCount(0, $this->history);

        $noSecret = $this->client([], self::BASE, '');
        $result = $noSecret->ping();
        $this->assertFalse($result->ok());
        $this->assertSame('not_configured', $result->errorCode());
        $this->assertSame(0, $result->status());
        $this->assertSame(0, $result->latencyMs());
        $this->assertCount(0, $this->history);
    }
}

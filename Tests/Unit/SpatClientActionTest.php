<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\Signer;
use Modules\SpatBridge\Services\SpatClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FakeOptionStore;

class SpatClientActionTest extends TestCase
{
    private const BASE = 'https://wp.example';
    private const SECRET = 'a-shared-secret-of-at-least-32-chars';
    private const NOW = 1700000000;
    private const KEY = '9f1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e';

    /** @var array<int, array<string, mixed>> */
    private $history = [];

    private function client(array $responses, string $base = self::BASE): SpatClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $options = new FakeOptionStore();
        $options->set('spatbridge.wp_base_url', $base);
        $options->set('spatbridge.shared_secret', self::SECRET);

        return new SpatClient(new Client(['handler' => $stack]), $options, new NullLogger(), function () {
            return self::NOW;
        });
    }

    private function wpError(int $status, string $code, string $message = 'x'): Response
    {
        return new Response($status, [], json_encode([
            'code' => $code,
            'message' => $message,
            'data' => ['status' => $status],
        ]));
    }

    private function match(
        SpatClient $client,
        bool $dryRun = true,
        array $params = ['log_id' => 12, 'order_id' => 5107]
    ): ClientResult {
        return $client->action('etransfers', 'match', $params, $dryRun, self::KEY, 'agent@x.test', 'Cody L');
    }

    public function test_action_posts_a_signed_body_with_dry_run_key_and_actor(): void
    {
        $client = $this->client([new Response(200, [], '{"success":true,"summary":"S"}')], self::BASE . '/');
        $result = $this->match($client);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $body = (string) $request->getBody();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://wp.example/wp-json/spat/v1/freescout/action', (string) $request->getUri());
        $this->assertSame((string) self::NOW, $request->getHeaderLine(Signer::HEADER_TIMESTAMP));
        $this->assertSame(
            Signer::sign((string) self::NOW, $body, self::SECRET),
            $request->getHeaderLine(Signer::HEADER_SIGNATURE)
        );
        $this->assertSame([
            'feature' => 'etransfers',
            'action' => 'match',
            'params' => ['log_id' => 12, 'order_id' => 5107],
            'dry_run' => true,
            'idempotency_key' => self::KEY,
            'actor' => ['email' => 'agent@x.test', 'name' => 'Cody L'],
        ], json_decode($body, true));
        $this->assertTrue($result->ok());
        $this->assertSame('S', $result->data()['summary']);
    }

    public function test_action_uses_the_longer_action_timeouts(): void
    {
        $client = $this->client([new Response(200, [], '{}')]);
        $this->match($client, false);

        $this->assertSame(15, $this->history[0]['options']['timeout']);
        $this->assertSame(2, $this->history[0]['options']['connect_timeout']);
        $this->assertFalse(json_decode((string) $this->history[0]['request']->getBody(), true)['dry_run']);
    }

    public function test_empty_params_are_sent_as_a_json_object(): void
    {
        $client = $this->client([new Response(200, [], '{}')]);
        $this->match($client, true, []);

        $this->assertStringContainsString('"params":{}', (string) $this->history[0]['request']->getBody());
    }

    public function test_query_posts_feature_query_and_params(): void
    {
        $client = $this->client([new Response(200, [], '{"results":[{"order_id":5107}]}')]);
        $result = $client->query('etransfers', 'search', ['q' => 'Pat']);

        $request = $this->history[0]['request'];
        $body = (string) $request->getBody();
        $this->assertSame('https://wp.example/wp-json/spat/v1/freescout/query', (string) $request->getUri());
        $this->assertSame(
            ['feature' => 'etransfers', 'query' => 'search', 'params' => ['q' => 'Pat']],
            json_decode($body, true)
        );
        $this->assertSame(
            Signer::sign((string) self::NOW, $body, self::SECRET),
            $request->getHeaderLine(Signer::HEADER_SIGNATURE)
        );
        $this->assertSame(15, $this->history[0]['options']['timeout']);
        $this->assertSame(2, $this->history[0]['options']['connect_timeout']);
        $this->assertSame([['order_id' => 5107]], $result->data()['results']);
    }

    /**
     * @return array<string, array{0: Response, 1: string, 2: string}>
     */
    public function actionErrors(): array
    {
        return [
            'actor unknown' => [
                $this->wpError(403, 'spat_freescout_actor_unknown', 'No WordPress account for agent@x.test'),
                'actor_unknown',
                'No WordPress account for agent@x.test',
            ],
            'actor forbidden' => [
                $this->wpError(403, 'spat_freescout_actor_forbidden', 'Sorry, not allowed.'),
                'actor_forbidden',
                'Sorry, not allowed.',
            ],
            'unknown action' => [$this->wpError(404, 'spat_freescout_unknown_action'), 'unknown_action', 'x'],
            'unknown query' => [$this->wpError(404, 'spat_freescout_unknown_query'), 'unknown_action', 'x'],
            'handler 409' => [
                $this->wpError(409, 'amount_mismatch', 'Amounts differ: e-Transfer $300.00, order $325.00'),
                'action_error',
                'Amounts differ: e-Transfer $300.00, order $325.00',
            ],
            'handler 500' => [
                $this->wpError(500, 'completion_failed', 'The order could not be completed.'),
                'action_error',
                'The order could not be completed.',
            ],
            'bad request' => [
                $this->wpError(400, 'spat_freescout_bad_request', 'Bad idempotency key.'),
                'action_error',
                'Bad idempotency key.',
            ],
            'error without a message' => [new Response(500, [], 'oops'), 'http_error', ''],
            'route missing still means outdated' => [$this->wpError(404, 'rest_no_route'), 'spat_outdated', ''],
            'bad signature still mapped' => [
                $this->wpError(403, 'spat_freescout_invalid_signature'),
                'bad_signature',
                '',
            ],
            'rate limit still mapped' => [$this->wpError(429, 'spat_freescout_rate_limited'), 'rate_limited', ''],
        ];
    }

    /**
     * @dataProvider actionErrors
     */
    public function test_maps_action_errors(Response $response, string $code, string $detail): void
    {
        $result = $this->match($this->client([$response]));

        $this->assertFalse($result->ok());
        $this->assertSame($code, $result->errorCode());
        $this->assertSame($detail, $result->detail());
        $this->assertSame($response->getStatusCode(), $result->status());
    }

    public function test_query_maps_errors_the_same_way(): void
    {
        $result = $this->client([$this->wpError(404, 'spat_freescout_unknown_query')])
            ->query('etransfers', 'nope', []);

        $this->assertSame('unknown_action', $result->errorCode());
    }

    public function test_customer_route_keeps_the_plain_http_error(): void
    {
        $result = $this->client([$this->wpError(409, 'whatever', 'A message')])->customer(['a@example.com']);

        $this->assertSame('http_error', $result->errorCode());
        $this->assertSame('', $result->detail());
    }

    public function test_action_timeout_is_timeout_and_never_throws(): void
    {
        $client = $this->client([
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', self::BASE)),
        ]);

        $this->assertSame('timeout', $this->match($client, false)->errorCode());
    }

    public function test_action_without_configuration_makes_no_request(): void
    {
        $this->assertSame('not_configured', $this->match($this->client([], ''))->errorCode());
        $this->assertCount(0, $this->history);
    }

    /**
     * @dataProvider actionLogLevelProvider
     */
    public function test_a_refusal_wordpress_explains_is_a_warning_anything_else_an_error(
        Response $response,
        string $level
    ): void {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public $records = [];

            public function log($level, $message, array $context = [])
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $options = new FakeOptionStore();
        $options->set('spatbridge.wp_base_url', self::BASE);
        $options->set('spatbridge.shared_secret', self::SECRET);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
        $client = new SpatClient($http, $options, $logger);

        $this->match($client);

        $this->assertCount(1, $logger->records);
        $this->assertSame($level, $logger->records[0][0]);
    }

    public function actionLogLevelProvider(): array
    {
        return [
            'refused with a reason' => [$this->wpError(409, 'spat_email_in_use', 'Taken.'), 'warning'],
            'actor forbidden' => [$this->wpError(403, 'spat_freescout_actor_forbidden'), 'warning'],
            'actor unknown' => [$this->wpError(403, 'spat_freescout_actor_unknown'), 'warning'],
            'unknown action' => [$this->wpError(404, 'spat_freescout_unknown_action'), 'warning'],
            'no reason given' => [new Response(500, [], 'boom'), 'error'],
            'bad signature' => [$this->wpError(403, 'spat_freescout_invalid_signature'), 'error'],
        ];
    }
}

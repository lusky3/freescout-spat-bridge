<?php

namespace Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SpatBridge\Http\Controllers\EventsController;
use Modules\SpatBridge\Services\DatabaseEventStore;
use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\EventRouter;
use Modules\SpatBridge\Services\Signer;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Tests\Support\FakeEventStore;
use Tests\Support\FakeOptionStore;

class EventsControllerTest extends TestCase
{
    private const SECRET = 'test-secret';
    private const ID = '1c9e6f3a-2b4d-4c8e-9f00-0123456789ab';

    /** @var FakeOptionStore */
    private $options;
    /** @var FakeEventStore */
    private $store;
    /** @var TestLogger */
    private $logger;
    /** @var EventRouter&object{events: array, result: array|null, failWith: \Throwable|null} */
    private $router;

    protected function setUp(): void
    {
        $this->options = new FakeOptionStore();
        $this->options->set('spatbridge.shared_secret', self::SECRET);
        $this->store = new FakeEventStore();
        $this->logger = new TestLogger();
        $this->router = new class extends EventRouter {
            /** @var array<int, array> */
            public $events = [];
            /** @var array */
            public $result = ['outcome' => 'noted', 'conversation_ids' => [11, 12], 'detail' => ''];
            /** @var \Throwable|null */
            public $failWith;

            public function __construct()
            {
            }

            public function route(array $event): array
            {
                $this->events[] = $event;
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }

                return $this->result;
            }
        };
    }

    private function controller(): EventsController
    {
        return new EventsController($this->options, $this->store, $this->router, new EventCatalog(), $this->logger);
    }

    private function body(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'id' => self::ID,
            'type' => 'etransfer.unmatched',
            'email' => 'Payer@Example.com',
            'occurred_at' => '2026-10-26T14:02:00Z',
            'data' => ['log_id' => 12, 'amount' => '325.00', 'from_name' => 'Pat Smith', 'reference' => 'CA8kQ2'],
        ], $overrides));
    }

    private function request(string $body, ?int $timestamp = null, ?string $signature = null): Request
    {
        $timestamp = (string) ($timestamp ?? time());
        $signature = $signature ?? Signer::sign($timestamp, $body, self::SECRET);

        return Request::create('/spatbridge/events', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SPAT_TIMESTAMP' => $timestamp,
            'HTTP_X_SPAT_SIGNATURE' => $signature,
        ], $body);
    }

    private function receive(Request $request): JsonResponse
    {
        return $this->controller()->receive($request);
    }

    public function test_handled_event_is_logged_with_its_outcome(): void
    {
        $body = $this->body();

        $response = $this->receive($this->request($body));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'ok', 'outcome' => 'noted'], $response->getData(true));
        $this->assertCount(1, $this->router->events);
        $this->assertSame([
            'id' => self::ID,
            'type' => 'etransfer.unmatched',
            'email' => 'payer@example.com',
            'occurred_at' => '2026-10-26T14:02:00Z',
            'data' => ['log_id' => 12, 'amount' => '325.00', 'from_name' => 'Pat Smith', 'reference' => 'CA8kQ2'],
        ], $this->router->events[0]);
        $this->assertSame($this->router->events[0], $this->store->rows[1]['event']);
        $this->assertSame($body, $this->store->rows[1]['payload']);
        $this->assertSame([[1, 'noted', [11, 12], '']], $this->store->finished);
    }

    public function test_no_secret_is_503(): void
    {
        $this->options->set('spatbridge.shared_secret', '');

        $response = $this->receive($this->request($this->body()));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame([], $this->store->rows);
    }

    public function test_bad_signature_is_403(): void
    {
        $response = $this->receive($this->request($this->body(), null, str_repeat('0', 64)));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->store->rows);
        $this->assertSame([], $this->router->events);
    }

    public function test_body_changed_after_signing_is_403(): void
    {
        $timestamp = time();
        $signature = Signer::sign((string) $timestamp, $this->body(), self::SECRET);

        $changed = $this->body(['email' => 'other@example.com']);

        $response = $this->receive($this->request($changed, $timestamp, $signature));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_stale_timestamp_is_403(): void
    {
        $response = $this->receive($this->request($this->body(), time() - 301));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->store->rows);
    }

    public function test_missing_signature_headers_are_403(): void
    {
        $request = Request::create('/spatbridge/events', 'POST', [], [], [], [], $this->body());

        $this->assertSame(403, $this->receive($request)->getStatusCode());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function badBodies(): array
    {
        $base = json_decode($this->body(), true);
        $without = function (string $key) use ($base) {
            unset($base[$key]);

            return (string) json_encode($base);
        };
        $with = function (string $key, $value) use ($base) {
            $base[$key] = $value;

            return (string) json_encode($base);
        };

        return [
            'not json' => ['{nope'],
            'json list' => ['[1,2]'],
            'unknown type' => [$with('type', 'order.created')],
            'bad uuid' => [$with('id', 'not-a-uuid')],
            'uuid with extra' => [$with('id', self::ID . '0')],
            'missing id' => [$without('id')],
            'bad email' => [$with('email', 'not-an-email')],
            'email not a string' => [$with('email', 5)],
            'missing email' => [$without('email')],
            'bad occurred_at' => [$with('occurred_at', 'yesterday')],
            'impossible date' => [$with('occurred_at', '2026-13-45T14:02:00Z')],
            'data not an object' => [$with('data', 'x')],
            'missing data' => [$without('data')],
        ];
    }

    /**
     * @dataProvider badBodies
     */
    public function test_bad_body_is_400(string $body): void
    {
        $response = $this->receive($this->request($body));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->store->rows);
        $this->assertSame([], $this->router->events);
    }

    public function test_oversized_body_is_400(): void
    {
        $body = $this->body(['data' => ['from_name' => str_repeat('x', 70000)]]);

        $this->assertSame(400, $this->receive($this->request($body))->getStatusCode());
        $this->assertSame([], $this->store->rows);
    }

    public function test_empty_email_and_offset_timestamps_are_accepted(): void
    {
        $body = $this->body(['email' => '', 'occurred_at' => '2026-10-26T10:02:00.123-04:00', 'data' => []]);

        $response = $this->receive($this->request($body));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $this->router->events[0]['email']);
        $this->assertSame([], $this->router->events[0]['data']);
    }

    public function test_duplicate_id_is_acknowledged_without_routing_again(): void
    {
        $body = $this->body();
        $this->receive($this->request($body));

        $response = $this->receive($this->request($body));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'duplicate'], $response->getData(true));
        $this->assertCount(1, $this->router->events);
        $this->assertCount(1, $this->store->rows);
        $this->assertCount(1, $this->store->finished);
    }

    public function test_routing_exception_is_logged_and_still_acknowledged(): void
    {
        $this->router->failWith = new \RuntimeException('FreeScout did not create the note.');

        $response = $this->receive($this->request($this->body()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'ok', 'outcome' => 'error'], $response->getData(true));
        $this->assertSame([[1, 'error', [], 'FreeScout did not create the note.']], $this->store->finished);
        $this->assertTrue($this->logger->hasErrorThatContains('FreeScout did not create the note.'));
    }

    public function test_failing_to_store_the_event_is_a_retryable_500(): void
    {
        $this->store->insertFails = new \RuntimeException('database is down');

        $response = $this->receive($this->request($this->body()));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([], $this->router->events);
        $this->assertTrue($this->logger->hasErrorThatContains('database is down'));
    }

    public function test_failing_to_record_the_outcome_is_still_acknowledged(): void
    {
        $store = new class extends FakeEventStore {
            public function finish(int $rowId, string $outcome, array $conversationIds, string $detail): void
            {
                throw new \RuntimeException('lost connection');
            }
        };
        $this->store = $store;

        $response = $this->receive($this->request($this->body()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'ok', 'outcome' => 'noted'], $response->getData(true));
        $this->assertTrue($this->logger->hasErrorThatContains('lost connection'));
    }

    public function test_long_details_are_cut_to_fit_the_log(): void
    {
        $this->router->failWith = new \RuntimeException(str_repeat('é', 300));

        $this->receive($this->request($this->body()));

        $this->assertSame(255, mb_strlen($this->store->finished[0][3]));
    }

    public function test_conversation_id_list_is_cut_at_a_comma_to_fit_the_log(): void
    {
        $this->assertSame('11,12', DatabaseEventStore::idList([11, '12']));

        $list = DatabaseEventStore::idList(range(100000, 100100));
        $this->assertLessThanOrEqual(255, strlen($list));
        // 36 six-digit ids and 35 commas: 251 characters.
        $this->assertStringEndsWith(',100035', $list);
    }
}

<?php

namespace Tests\Unit;

use App\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\FeatureRegistry;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Http\Controllers\ActionController;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\NoteWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\FakeCache;
use Tests\Support\FakeCustomer;
use Tests\Support\FakeOptionStore;
use Tests\Support\FakeSpatClient;

class ActionControllerTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const CUSTOMER_EMAIL = 'Jordan.Smith@Example.com';

    /** @var FakeSpatClient */
    private $client;
    /** @var FakeOptionStore */
    private $options;
    /** @var object */
    private $conversation;
    /** @var object In another mailbox, with another id. */
    private $otherConversation;
    /** @var string The key the run requests post; previewed() sets it. */
    private $previewKey = self::KEY;

    protected function setUp(): void
    {
        FakeCache::reset();
        Thread::reset();
        $this->client = new FakeSpatClient();
        $this->options = new FakeOptionStore();

        $this->conversation = new \stdClass();
        $this->conversation->id = 77;
        $this->conversation->customer_email = self::CUSTOMER_EMAIL;
        $this->conversation->customer = new FakeCustomer(self::CUSTOMER_EMAIL);
        $this->conversation->mailbox_id = 3;

        $this->otherConversation = clone $this->conversation;
        $this->otherConversation->id = 78;
        $this->otherConversation->mailbox_id = 5;
    }

    private function feature(?array $override = null): Feature
    {
        return new class ($override) implements Feature {
            /** @var array|null */
            private $override;

            public function __construct(?array $override)
            {
                $this->override = $override;
            }

            public function key(): string
            {
                return 'etransfers';
            }

            public function label(): string
            {
                return 'e-Transfers';
            }

            public function providerKeys(): array
            {
                return ['etransfers'];
            }

            public function present(array $sections): ?Panel
            {
                return null;
            }

            public function actionKeys(): array
            {
                return ['match', 'unmatch'];
            }

            public function previewOverride(string $action, array $params): ?array
            {
                return $this->override;
            }

            public function mapConfirmCodes(string $action, array $codes, array $params): array
            {
                if (in_array('amount_mismatch', $codes, true)) {
                    $params['force_mismatch'] = true;
                }

                return $params;
            }
        };
    }

    /**
     * @return ActionController&object{conversations: array, rendered: array}
     */
    private function controller(?array $override = null)
    {
        $registry = new FeatureRegistry();
        $registry->register($this->feature($override));

        $controller = new class (
            $this->client,
            $registry,
            $this->options,
            new NoteWriter(),
            new NullLogger()
        ) extends ActionController {
            /** @var array<int, object> */
            public $conversations = [];
            /** @var array<int, array{0: string, 1: array, 2: int}> */
            public $rendered = [];

            protected function findConversation(int $id)
            {
                return $this->conversations[$id] ?? null;
            }

            protected function renderView(string $view, array $data, int $status)
            {
                $this->rendered[] = [$view, $data, $status];

                return $status;
            }
        };
        $controller->conversations = [77 => $this->conversation, 78 => $this->otherConversation];

        return $controller;
    }

    private function user(bool $admin = true, bool $canView = true, int $id = 4)
    {
        return new class ($admin, $canView, $id) {
            public $id;
            public $email = 'agent@x.test';
            private $admin;
            private $canView;

            public function __construct(bool $admin, bool $canView, int $id)
            {
                $this->admin = $admin;
                $this->canView = $canView;
                $this->id = $id;
            }

            public function isAdmin(): bool
            {
                return $this->admin;
            }

            public function can($ability, $subject): bool
            {
                return $ability === 'view' && $this->canView;
            }

            public function getFullName(): string
            {
                return 'Cody L';
            }
        };
    }

    private function request(string $method, array $input, $user): Request
    {
        $request = Request::create('/spatbridge/action', $method, $input);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    private function previewRequest($user, array $overrides = []): Request
    {
        return $this->request('GET', array_merge([
            'feature' => 'etransfers',
            'action' => 'match',
            'params' => '{"log_id":12,"order_id":5107}',
            'conversation_id' => '77',
        ], $overrides), $user);
    }

    private function runRequest($user, array $overrides = []): Request
    {
        return $this->request('POST', array_merge([
            'feature' => 'etransfers',
            'action' => 'match',
            'params' => '{"log_id":12,"order_id":5107}',
            'conversation_id' => '77',
            'idempotency_key' => $this->previewKey,
        ], $overrides), $user);
    }

    /**
     * Shows the agent the preview a Run must match (a dry run answered by
     * WordPress), then forgets that call so run assertions start at index 0.
     *
     * @param ActionController $controller
     * @return string The preview's idempotency key, also used by runRequest().
     */
    private function previewed($controller, $user = null, array $overrides = []): string
    {
        array_unshift($this->client->results, $this->dryRun());
        $controller->preview($this->previewRequest($user ?: $this->user(), $overrides));
        $this->assertSame('spatbridge::action.preview', end($controller->rendered)[0]);

        $this->previewKey = end($this->client->calls)[1]['key'];
        $this->client->calls = [];

        return $this->previewKey;
    }

    private function dryRun(array $extra = []): ClientResult
    {
        return ClientResult::success(200, array_merge([
            'success' => true,
            'dry_run' => true,
            'replayed' => false,
            'summary' => 'Match e-Transfer CA8kQ2 ($325.00 from Pat Smith) to order #5107',
            'changes' => ['Order #5107: on hold → completed', 'e-Transfer CA8kQ2 recorded as manually matched'],
            'requires_confirm' => [
                ['code' => 'amount_mismatch', 'message' => 'Amounts differ: e-Transfer $300.00, order $325.00'],
            ],
            'data' => [],
        ], $extra), 10);
    }

    private function realRun(array $extra = []): ClientResult
    {
        return ClientResult::success(200, array_merge([
            'success' => true,
            'dry_run' => false,
            'replayed' => false,
            'summary' => 'Matched <script>alert(1)</script> to order #5107',
            'changes' => ['Order #5107: on hold → completed'],
            'requires_confirm' => [],
            'data' => [],
        ], $extra), 10);
    }

    private function json(JsonResponse $response): array
    {
        return json_decode($response->getContent(), true);
    }

    private function cacheKey(): string
    {
        return 'spatbridge.customer.' . md5(strtolower(self::CUSTOMER_EMAIL));
    }

    // ---- preview ---------------------------------------------------------

    public function test_preview_calls_a_dry_run_and_renders_the_partial(): void
    {
        $this->client->results[] = $this->dryRun();
        $controller = $this->controller();

        $this->assertSame(200, $controller->preview($this->previewRequest($this->user())));

        $this->assertCount(1, $this->client->calls);
        [$kind, $call] = $this->client->calls[0];
        $this->assertSame('action', $kind);
        $this->assertSame('etransfers', $call['feature']);
        $this->assertSame('match', $call['action']);
        $this->assertSame(['log_id' => 12, 'order_id' => 5107], $call['params']);
        $this->assertTrue($call['dry_run']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9-]{8,64}$/', $call['key']);
        $this->assertSame('agent@x.test', $call['actor_email']);
        $this->assertSame('Cody L', $call['actor_name']);

        [$view, $data, $status] = $controller->rendered[0];
        $this->assertSame('spatbridge::action.preview', $view);
        $this->assertSame(200, $status);
        $this->assertSame($call['key'], $data['idempotencyKey']);
        $this->assertSame('Match e-Transfer CA8kQ2 ($325.00 from Pat Smith) to order #5107', $data['summary']);
        $this->assertSame(
            ['Order #5107: on hold → completed', 'e-Transfer CA8kQ2 recorded as manually matched'],
            $data['changes']
        );
        $this->assertSame(
            [['code' => 'amount_mismatch', 'message' => 'Amounts differ: e-Transfer $300.00, order $325.00']],
            $data['confirms']
        );
        $this->assertSame('etransfers', $data['feature']);
        $this->assertSame('match', $data['action']);
        $this->assertSame(['log_id' => 12, 'order_id' => 5107], json_decode($data['paramsJson'], true));
        $this->assertSame(77, $data['conversationId']);
    }

    public function test_each_preview_gets_a_fresh_key(): void
    {
        $this->client->results = [$this->dryRun(), $this->dryRun()];
        $controller = $this->controller();
        $controller->preview($this->previewRequest($this->user()));
        $controller->preview($this->previewRequest($this->user()));

        $this->assertNotSame($this->client->calls[0][1]['key'], $this->client->calls[1][1]['key']);
    }

    public function test_preview_drops_malformed_wire_values(): void
    {
        $this->client->results[] = ClientResult::success(200, [
            'summary' => ['not', 'a', 'string'],
            'changes' => ['ok', 5, ['x'], null],
            'requires_confirm' => [
                ['code' => 'a'],
                'junk',
                ['message' => 'no code'],
                ['code' => 'b', 'message' => 'B'],
            ],
        ], 1);
        $controller = $this->controller();
        $controller->preview($this->previewRequest($this->user()));

        $data = $controller->rendered[0][1];
        $this->assertSame('', $data['summary']);
        $this->assertSame(['ok', '5'], $data['changes']);
        $this->assertSame([['code' => 'a', 'message' => 'a'], ['code' => 'b', 'message' => 'B']], $data['confirms']);
    }

    public function test_preview_partial_carries_the_key_changes_and_confirm_boxes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Resources/views/action/preview.blade.php');

        $this->assertStringContainsString('data-idempotency-key="{{ $idempotencyKey }}"', $source);
        $this->assertStringContainsString('@foreach ($changes as $change)', $source);
        $this->assertStringContainsString('@foreach ($confirms as $confirm)', $source);
        $this->assertStringContainsString('class="spatbridge-action-confirm"', $source);
        $this->assertStringContainsString('spatbridge-action-run', $source);
        $this->assertStringNotContainsString('{!!', $source);
    }

    public function test_preview_uses_the_feature_override_instead_of_a_dry_run(): void
    {
        $controller = $this->controller(['view' => 'spatbridge::action.etransfer_pick', 'data' => ['mode' => 'order']]);
        $controller->preview($this->previewRequest($this->user(), ['params' => '{"log_id":12}']));

        $this->assertSame([], $this->client->calls);
        [$view, $data, $status] = $controller->rendered[0];
        $this->assertSame('spatbridge::action.etransfer_pick', $view);
        $this->assertSame(200, $status);
        $this->assertSame('order', $data['mode']);
        $this->assertSame(77, $data['conversationId']);
        $this->assertSame(['log_id' => 12], $data['params']);
    }

    /**
     * @return array<string, array{0: ClientResult, 1: string}>
     */
    public function previewErrors(): array
    {
        return [
            'actor unknown' => [
                ClientResult::failure('actor_unknown', 403, 0, 'No WordPress account for agent@x.test'),
                'No WordPress account for agent@x.test',
            ],
            'actor forbidden' => [
                ClientResult::failure('actor_forbidden', 403, 0, 'x'),
                "Your WordPress account isn't allowed to do this.",
            ],
            'unknown action' => [
                ClientResult::failure('unknown_action', 404),
                "WordPress doesn't support this action. Update SportsPress Admin Tools.",
            ],
            'action error' => [
                ClientResult::failure('action_error', 409, 0, 'This e-Transfer is already matched.'),
                'This e-Transfer is already matched.',
            ],
            'transport' => [ClientResult::failure('network'), "Couldn't reach WordPress. Check the site URL."],
        ];
    }

    /**
     * @dataProvider previewErrors
     */
    public function test_preview_errors_render_the_error_partial(ClientResult $result, string $message): void
    {
        $this->client->results[] = $result;
        $controller = $this->controller();
        $controller->preview($this->previewRequest($this->user()));

        $this->assertSame(['spatbridge::action.error', ['message' => $message], 200], $controller->rendered[0]);
    }

    public function test_preview_forbidden_without_view_permission(): void
    {
        $controller = $this->controller();
        $this->assertSame(200, $controller->preview($this->previewRequest($this->user(true, false))));
        $this->assertSame(
            ['spatbridge::action.error', ['message' => "You don't have access to this conversation."], 200],
            $controller->rendered[0]
        );
        $this->assertSame([], $this->client->calls);
    }

    public function test_preview_missing_conversation_is_not_found(): void
    {
        $controller = $this->controller();
        $this->assertSame(200, $controller->preview($this->previewRequest($this->user(), ['conversation_id' => '5'])));
        $this->assertSame(['message' => 'Conversation not found.'], $controller->rendered[0][1]);
        $this->assertSame([], $this->client->calls);
    }

    public function test_preview_forbidden_without_action_permission(): void
    {
        $controller = $this->controller();
        $this->assertSame(200, $controller->preview($this->previewRequest($this->user(false))));
        $this->assertSame(
            ['spatbridge::action.error', ['message' => "You don't have permission to run this action."], 200],
            $controller->rendered[0]
        );
        $this->assertSame([], $this->client->calls);
    }

    public function test_preview_forbidden_when_the_feature_is_switched_off(): void
    {
        $this->options->set('spatbridge.features.etransfers.enabled', false);
        $controller = $this->controller();

        $this->assertSame(200, $controller->preview($this->previewRequest($this->user())));
        $this->assertSame(['message' => "You don't have permission to run this action."], $controller->rendered[0][1]);
        $this->assertSame([], $this->client->calls);
    }

    public function test_preview_refuses_an_undeclared_action(): void
    {
        $controller = $this->controller();

        $this->assertSame(200, $controller->preview($this->previewRequest($this->user(), ['action' => 'refund'])));
        $this->assertSame(['message' => "This action isn't available."], $controller->rendered[0][1]);
        $this->assertSame([], $this->client->calls);
    }

    public function test_array_inputs_are_refused_not_fatal(): void
    {
        $controller = $this->controller();

        $controller->preview($this->previewRequest($this->user(), ['action' => ['match']]));
        $controller->preview($this->previewRequest($this->user(), ['feature' => ['x']]));
        $this->assertSame("This action isn't available.", $controller->rendered[0][1]['message']);
        $this->assertSame("You don't have permission to run this action.", $controller->rendered[1][1]['message']);
        $this->assertSame([], $this->client->calls);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function badParams(): array
    {
        return [
            'not json' => ['{log_id:12'],
            'a json string' => ['"12"'],
            'a json number' => ['12'],
            'an array input' => [['log_id' => 12]],
        ];
    }

    /**
     * @dataProvider badParams
     * @param mixed $params
     */
    public function test_preview_rejects_params_that_are_not_a_json_object($params): void
    {
        $controller = $this->controller();

        $this->assertSame(200, $controller->preview($this->previewRequest($this->user(), ['params' => $params])));
        $this->assertSame("The action's details couldn't be read.", $controller->rendered[0][1]['message']);
        $this->assertSame([], $this->client->calls);
    }

    // ---- run -------------------------------------------------------------

    public function test_run_success_adds_an_escaped_note_and_forgets_the_customer_cache(): void
    {
        FakeCache::$store[$this->cacheKey()] = ['sections' => []];
        $this->client->results[] = $this->realRun();
        $controller = $this->controller();
        $this->previewed($controller);

        $response = $controller->run($this->runRequest($this->user()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['status' => 'success', 'msg' => 'Matched <script>alert(1)</script> to order #5107'],
            $this->json($response)
        );

        $call = $this->client->calls[0][1];
        $this->assertFalse($call['dry_run']);
        $this->assertSame($this->previewKey, $call['key']);

        $this->assertCount(1, Thread::$created);
        [$data, $conversation, $customer] = Thread::$created[0];
        $this->assertSame(Thread::TYPE_NOTE, $data['type']);
        $this->assertSame(4, $data['created_by_user_id']);
        $this->assertSame(
            NoteWriter::actionBody(
                'Matched <script>alert(1)</script> to order #5107',
                ['Order #5107: on hold → completed']
            ),
            $data['body']
        );
        $this->assertStringNotContainsString('<script>', $data['body']);
        $this->assertSame($this->conversation, $conversation);
        $this->assertSame($this->conversation->customer, $customer);

        $this->assertArrayNotHasKey($this->cacheKey(), FakeCache::$store);
    }

    public function test_run_error_adds_no_note_and_keeps_the_cache(): void
    {
        FakeCache::$store[$this->cacheKey()] = ['sections' => []];
        $this->client->results[] = ClientResult::failure(
            'action_error',
            409,
            0,
            'Amounts differ: e-Transfer $300.00, order $325.00'
        );
        $controller = $this->controller();
        $this->previewed($controller);

        $response = $controller->run($this->runRequest($this->user()));

        $this->assertSame(
            ['status' => 'error', 'msg' => 'Amounts differ: e-Transfer $300.00, order $325.00'],
            $this->json($response)
        );
        $this->assertSame([], Thread::$created);
        $this->assertArrayHasKey($this->cacheKey(), FakeCache::$store);
    }

    public function test_run_timeout_is_a_plain_error_with_no_note(): void
    {
        $this->client->results[] = ClientResult::failure('timeout');
        $controller = $this->controller();
        $this->previewed($controller);

        $response = $controller->run($this->runRequest($this->user()));

        $this->assertSame(
            ['status' => 'error', 'msg' => "WordPress didn't answer within 15 seconds."],
            $this->json($response)
        );
        $this->assertSame([], Thread::$created);
    }

    public function test_double_submit_reuses_the_posted_key_and_notes_once(): void
    {
        $this->client->results = [$this->realRun(), $this->realRun(['replayed' => true])];
        $controller = $this->controller();
        $this->previewed($controller);

        $first = $controller->run($this->runRequest($this->user()));
        $second = $controller->run($this->runRequest($this->user()));

        $this->assertSame($this->previewKey, $this->client->calls[0][1]['key']);
        $this->assertSame($this->previewKey, $this->client->calls[1][1]['key']);
        $this->assertSame('success', $this->json($first)['status']);
        $this->assertSame('success', $this->json($second)['status']);
        $this->assertCount(1, Thread::$created);
    }

    public function test_replay_after_a_lost_answer_still_records_the_note(): void
    {
        // The first Run timed out after WordPress had finished; the retry is replayed.
        $this->client->results = [ClientResult::failure('timeout'), $this->realRun(['replayed' => true])];
        $controller = $this->controller();
        $this->previewed($controller);

        $controller->run($this->runRequest($this->user()));
        $controller->run($this->runRequest($this->user()));

        $this->assertCount(1, Thread::$created);
    }

    public function test_run_maps_ticked_confirm_codes_into_params(): void
    {
        $this->client->results[] = $this->realRun();
        $controller = $this->controller();
        $this->previewed($controller);
        $controller->run($this->runRequest($this->user(), ['confirm' => ['amount_mismatch']]));

        $this->assertSame(
            ['log_id' => 12, 'order_id' => 5107, 'force_mismatch' => true],
            $this->client->calls[0][1]['params']
        );
    }

    public function test_run_without_ticks_sends_the_params_unchanged(): void
    {
        $this->client->results[] = $this->realRun();
        $controller = $this->controller();
        $this->previewed($controller);
        $controller->run($this->runRequest($this->user()));

        $this->assertSame(['log_id' => 12, 'order_id' => 5107], $this->client->calls[0][1]['params']);
    }

    public function test_forged_run_without_action_permission_is_refused(): void
    {
        $response = $this->controller()->run($this->runRequest($this->user(false)));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['status' => 'error', 'msg' => "You don't have permission to run this action."],
            $this->json($response)
        );
        $this->assertSame([], $this->client->calls);
        $this->assertSame([], Thread::$created);
    }

    public function test_run_without_view_permission_is_refused(): void
    {
        $response = $this->controller()->run($this->runRequest($this->user(true, false)));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->client->calls);
    }

    public function test_run_refuses_an_undeclared_action(): void
    {
        $response = $this->controller()->run($this->runRequest($this->user(), ['action' => 'refund']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->client->calls);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function badKeys(): array
    {
        return [
            'missing' => [null],
            'too short' => ['abc'],
            'bad characters' => ['0123456789abcdef_0123'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    /**
     * @dataProvider badKeys
     * @param mixed $key
     */
    public function test_run_refuses_a_bad_idempotency_key($key): void
    {
        $response = $this->controller()->run($this->runRequest($this->user(), ['idempotency_key' => $key]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('error', $this->json($response)['status']);
        $this->assertSame([], $this->client->calls);
    }

    public function test_run_still_succeeds_when_the_note_fails(): void
    {
        Thread::$failWith = new \RuntimeException('db down');
        $this->client->results[] = $this->realRun(['summary' => 'Done']);
        $controller = $this->controller();
        $this->previewed($controller);

        $response = $controller->run($this->runRequest($this->user()));

        $this->assertSame(
            ['status' => 'success', 'msg' => "Done The internal note couldn't be added."],
            $this->json($response)
        );
    }

    public function test_run_without_a_preview_is_refused(): void
    {
        $this->client->results[] = $this->realRun();

        $response = $this->controller()->run($this->runRequest($this->user()));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            ['status' => 'error', 'msg' => 'This preview has expired. Close the window and open the action again.'],
            $this->json($response)
        );
        $this->assertSame([], $this->client->calls);
        $this->assertSame([], Thread::$created);
    }

    /**
     * @return array<string, array{0: array, 1: bool}>
     */
    public function mismatchedRuns(): array
    {
        return [
            'other params' => [['params' => '{"log_id":12,"order_id":9999}'], false],
            'other conversation' => [['conversation_id' => '78'], false],
            'other action' => [['action' => 'unmatch'], false],
            'other user' => [[], true],
            'other key' => [['idempotency_key' => 'ffffffff-0000-1111-2222'], false],
        ];
    }

    /**
     * @dataProvider mismatchedRuns
     */
    public function test_run_that_differs_from_the_preview_is_refused(array $overrides, bool $otherUser): void
    {
        $this->client->results[] = $this->realRun();
        $controller = $this->controller();
        $this->previewed($controller);

        $user = $otherUser ? $this->user(true, true, 9) : $this->user();
        $response = $controller->run($this->runRequest($user, $overrides));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->client->calls);
        $this->assertSame([], Thread::$created);
    }

    public function test_run_matches_the_preview_regardless_of_param_key_order(): void
    {
        $this->client->results[] = $this->realRun();
        $controller = $this->controller();
        $this->previewed($controller);

        $response = $controller->run($this->runRequest($this->user(), ['params' => '{"order_id":5107,"log_id":12}']));

        $this->assertSame('success', $this->json($response)['status']);
    }

    public function test_run_after_a_matching_preview_with_ticks_succeeds(): void
    {
        // Ticking amount_mismatch adds force_mismatch, which must not break the binding.
        $this->client->results[] = $this->realRun();
        $controller = $this->controller();
        $this->previewed($controller);

        $response = $controller->run($this->runRequest($this->user(), ['confirm' => ['amount_mismatch']]));

        $this->assertSame('success', $this->json($response)['status']);
        $this->assertCount(1, $this->client->calls);
        $this->assertCount(1, Thread::$created);
    }

    public function test_a_failed_preview_binds_nothing(): void
    {
        $this->client->results = [ClientResult::failure('network')];
        $controller = $this->controller();
        $controller->preview($this->previewRequest($this->user()));
        $this->previewKey = $this->client->calls[0][1]['key'];
        $this->client->calls = [];

        $response = $controller->run($this->runRequest($this->user()));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->client->calls);
    }

    public function test_run_answered_as_a_dry_run_is_an_error_without_a_note(): void
    {
        $this->client->results[] = $this->realRun(['dry_run' => true]);
        $controller = $this->controller();
        $this->previewed($controller);

        $response = $controller->run($this->runRequest($this->user()));

        $this->assertSame(
            ['status' => 'error', 'msg' => "WordPress didn't run the action. Try again."],
            $this->json($response)
        );
        $this->assertSame([], Thread::$created);
    }

    public function test_note_refused_by_freescout_is_reported_and_retried_on_replay(): void
    {
        Thread::$result = false;
        $this->client->results = [$this->realRun(['summary' => 'Done']), $this->realRun(['replayed' => true])];
        $controller = $this->controller();
        $this->previewed($controller);

        $response = $controller->run($this->runRequest($this->user()));
        $this->assertSame("Done The internal note couldn't be added.", $this->json($response)['msg']);

        // No "noted" marker was set, so the replayed retry writes the note.
        Thread::$result = true;
        $controller->run($this->runRequest($this->user()));
        $this->assertCount(1, Thread::$created);
    }

    public function test_conversation_in_an_excluded_mailbox_is_refused(): void
    {
        $this->options->set('spatbridge.mailboxes', [5]);
        $controller = $this->controller();

        $controller->preview($this->previewRequest($this->user()));
        $this->assertSame(
            ['spatbridge::action.error', ['message' => "ARL League isn't turned on for this mailbox."], 200],
            $controller->rendered[0]
        );

        $this->assertSame(403, $controller->run($this->runRequest($this->user()))->getStatusCode());
        $this->assertSame(403, $controller->query($this->queryRequest($this->user()))->getStatusCode());
        $this->assertSame([], $this->client->calls);
    }

    public function test_conversation_in_an_included_mailbox_is_allowed(): void
    {
        $this->options->set('spatbridge.mailboxes', ['5']);
        $this->client->results[] = $this->dryRun();
        $controller = $this->controller();

        $controller->preview($this->previewRequest($this->user(), ['conversation_id' => '78']));

        $this->assertSame('spatbridge::action.preview', $controller->rendered[0][0]);
    }

    // ---- query -----------------------------------------------------------

    private function queryRequest($user, array $overrides = []): Request
    {
        return $this->request('GET', array_merge([
            'feature' => 'etransfers',
            'query' => 'search',
            'params' => '{"q":"Pat"}',
            'conversation_id' => '77',
        ], $overrides), $user);
    }

    public function test_query_returns_the_results(): void
    {
        $this->client->results[] = ClientResult::success(200, ['results' => [['log_id' => 31]]], 1);

        $response = $this->controller()->query($this->queryRequest($this->user()));

        $this->assertSame(['status' => 'success', 'results' => [['log_id' => 31]]], $this->json($response));
        $this->assertSame(
            ['query', ['feature' => 'etransfers', 'query' => 'search', 'params' => ['q' => 'Pat']]],
            $this->client->calls[0]
        );
    }

    public function test_query_error_returns_the_message(): void
    {
        $this->client->results[] = ClientResult::failure('unknown_action', 404);

        $response = $this->controller()->query($this->queryRequest($this->user()));

        $this->assertSame(
            ['status' => 'error', 'msg' => "WordPress doesn't support this action. Update SportsPress Admin Tools."],
            $this->json($response)
        );
    }

    public function test_query_is_refused_without_permission(): void
    {
        $response = $this->controller()->query($this->queryRequest($this->user(false)));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->client->calls);
    }

    public function test_query_is_refused_without_view_permission(): void
    {
        $response = $this->controller()->query($this->queryRequest($this->user(true, false)));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->client->calls);
    }

    public function test_query_refuses_a_malformed_query_name(): void
    {
        $response = $this->controller()->query($this->queryRequest($this->user(), ['query' => '../ping']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->client->calls);
    }
}

<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\FeatureRegistry;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Http\Controllers\SpatBridgeSettingsController;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\MailboxLookup;
use Modules\SpatBridge\Services\Support\OptionBool;
use Modules\SpatBridge\Services\SpatClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeOptionStore;

/**
 * Request::validate() is a macro registered by illuminate/foundation, which
 * this suite doesn't load. setUp() registers the same macro body against a
 * minimal real container with only 'validator' bound, so validation behaves
 * like Laravel's (real rules, real ValidationException) without a booted app.
 */
class SpatBridgeSettingsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->singleton('validator', function () {
            return new ValidationFactory(new Translator(new ArrayLoader(), 'en'));
        });
        Container::setInstance($container);

        if (!Request::hasMacro('validate')) {
            // Port of FoundationServiceProvider::registerRequestValidate().
            Request::macro('validate', function (array $rules, ...$params) {
                Container::getInstance()->make('validator')->validate($this->all(), $rules, ...$params);

                return $this->only(collect($rules)->keys()->map(function ($rule) {
                    return str_contains($rule, '.') ? explode('.', $rule)[0] : $rule;
                })->unique()->toArray());
            });
        }
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function feature(string $key): Feature
    {
        return new class ($key) implements Feature {
            private $key;

            public function __construct(string $key)
            {
                $this->key = $key;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Label ' . $this->key;
            }

            public function providerKeys(): array
            {
                return ['player'];
            }

            public function present(array $sections): ?Panel
            {
                return null;
            }

            public function actionKeys(): array
            {
                return [];
            }

            public function queryKeys(): array
            {
                return [];
            }

            public function previewOverride(string $action, array $params): ?array
            {
                return null;
            }

            public function mapConfirmCodes(string $action, array $codes, array $params): array
            {
                return $params;
            }
        };
    }

    private function registry(): FeatureRegistry
    {
        $registry = new FeatureRegistry();
        $registry->register($this->feature('player_card'));
        $registry->register($this->feature('waitlist'));

        return $registry;
    }

    private function controller(FakeOptionStore $store): SpatBridgeSettingsController
    {
        $lookup = new class extends MailboxLookup {
            public function exists(int $id): bool
            {
                return in_array($id, [3, 5], true);
            }
        };

        return new SpatBridgeSettingsController($store, $this->registry(), $lookup);
    }

    private function store(): FakeOptionStore
    {
        $store = new FakeOptionStore();
        $store->set('spatbridge.shared_secret', str_repeat('a', 40));
        $store->set('spatbridge.wp_base_url', 'https://old.example.com');

        return $store;
    }

    private function request(array $overrides = []): Request
    {
        return Request::create('/app-settings/spatbridge/save', 'POST', array_merge([
            'wp_base_url' => 'https://example.com',
            'layout_default' => 'stacked',
        ], $overrides));
    }

    private function client(ClientResult $result): SpatClient
    {
        return new class ($result) extends SpatClient {
            private $result;

            public function __construct(ClientResult $result)
            {
                $this->result = $result;
            }

            public function ping(): ClientResult
            {
                return $this->result;
            }
        };
    }

    public function test_masked_secret_leaves_the_stored_secret_untouched(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['shared_secret' => str_repeat("\u{2022}", 16)]));

        $this->assertSame(str_repeat('a', 40), $store->get('spatbridge.shared_secret'));
        $this->assertSame('https://example.com', $store->get('spatbridge.wp_base_url'));
    }

    public function test_blank_or_missing_secret_leaves_the_stored_secret_untouched(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['shared_secret' => '']));
        $this->controller($store)->save($this->request());

        $this->assertSame(str_repeat('a', 40), $store->get('spatbridge.shared_secret'));
    }

    public function test_a_new_32_character_secret_is_saved(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['shared_secret' => str_repeat('z', 32)]));

        $this->assertSame(str_repeat('z', 32), $store->get('spatbridge.shared_secret'));
    }

    public function test_a_short_secret_fails_validation_and_writes_nothing(): void
    {
        $store = $this->store();

        try {
            $this->controller($store)->save($this->request(['shared_secret' => 'too-short']));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shared_secret', $e->validator->errors()->toArray());
        }

        $this->assertSame(str_repeat('a', 40), $store->get('spatbridge.shared_secret'));
        $this->assertSame('https://old.example.com', $store->get('spatbridge.wp_base_url'));
    }

    public function test_wp_base_url_is_stored_without_a_trailing_slash(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['wp_base_url' => 'https://example.com/']));

        $this->assertSame('https://example.com', $store->get('spatbridge.wp_base_url'));
    }

    public function test_an_invalid_url_fails_validation(): void
    {
        $store = $this->store();

        try {
            $this->controller($store)->save($this->request(['wp_base_url' => 'not-a-url']));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('wp_base_url', $e->validator->errors()->toArray());
        }
    }

    public function test_layout_default_accepts_only_stacked_or_tabbed(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['layout_default' => 'tabbed']));
        $this->assertSame('tabbed', $store->get('spatbridge.layout_default'));

        try {
            $this->controller($store)->save($this->request(['layout_default' => 'grid']));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('layout_default', $e->validator->errors()->toArray());
        }
        $this->assertSame('tabbed', $store->get('spatbridge.layout_default'));
    }

    public function test_mailboxes_are_stored_as_ints(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['mailboxes' => ['3', '7', '3']]));

        $this->assertSame([3, 7], $store->get('spatbridge.mailboxes'));
    }

    public function test_no_mailboxes_stores_an_empty_list(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request());

        $this->assertSame([], $store->get('spatbridge.mailboxes'));
    }

    public function test_feature_switch_absent_from_the_post_is_stored_false(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['features' => ['player_card' => '1']]));

        $this->assertTrue($store->get('spatbridge.features.player_card.enabled'));
        $this->assertFalse($store->get('spatbridge.features.waitlist.enabled'));
    }

    public function test_allow_agent_switch_is_a_bool_from_checkbox_presence(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['allow_agent_switch' => '1']));
        $this->assertTrue($store->get('spatbridge.allow_agent_switch'));

        $this->controller($store)->save($this->request());
        $this->assertFalse($store->get('spatbridge.allow_agent_switch'));
    }

    public function test_feature_order_keeps_only_known_unique_keys(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request([
            'feature_order' => ['waitlist', 'bogus', 'player_card', 'waitlist'],
        ]));

        $this->assertSame(['waitlist', 'player_card'], $store->get('spatbridge.feature_order'));
    }

    public function test_actors_are_saved_as_json_per_feature(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['actors' => [
            'player_card' => ['mode' => 'selected', 'user_ids' => ['4', '9', '4']],
            'waitlist' => ['mode' => 'users'],
        ]]));

        $this->assertSame(
            ['mode' => 'selected', 'user_ids' => [4, 9]],
            $store->get('spatbridge.features.player_card.actors')
        );
        $this->assertSame(
            ['mode' => 'users', 'user_ids' => []],
            $store->get('spatbridge.features.waitlist.actors')
        );
    }

    public function test_saved_actors_are_applied_when_read_back_through_a_freescout_like_store(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['actors' => [
            'player_card' => ['mode' => 'selected', 'user_ids' => ['4', '9']],
        ]]));

        $permissions = new \Modules\SpatBridge\Services\ActionPermissions($store);
        $this->assertSame('selected', $permissions->mode('player_card'));
        $this->assertSame([4, 9], $permissions->selectedUserIds('player_card'));
    }

    public function test_actors_for_unknown_features_are_ignored(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['actors' => ['bogus' => ['mode' => 'users']]]));

        $this->assertNull($store->get('spatbridge.features.bogus.actors'));
    }

    public function test_actors_reject_an_unknown_mode_and_non_integer_ids(): void
    {
        $store = $this->store();

        $bad = [
            ['player_card' => ['mode' => 'everyone']],
            ['player_card' => ['mode' => 'selected', 'user_ids' => ['abc']]],
        ];
        foreach ($bad as $actors) {
            try {
                $this->controller($store)->save($this->request(['actors' => $actors]));
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->validator->errors()->toArray());
            }
        }
        $this->assertNull($store->get('spatbridge.features.player_card.actors'));
    }

    private function assertRejected(FakeOptionStore $store, array $post, string $field): void
    {
        try {
            $this->controller($store)->save($this->request($post));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->validator->errors()->toArray());
        }
    }

    public function test_event_switches_tags_mailbox_and_note_user_are_saved(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request([
            'events_notes' => '1',
            'event_tag' => [
                'etransfer_unmatched' => '  unpaid-transfer ',
                'registration_incomplete' => '',
                'waitlist_offered' => 'offer sent',
            ],
            'event_create' => ['etransfer_unmatched' => '1', 'waitlist_expired' => '1'],
            'events_mailbox_id' => '5',
            'events_note_user_id' => '7',
        ]));

        $this->assertTrue($store->get('spatbridge.events.notes'));
        $this->assertFalse($store->get('spatbridge.events.tags'));
        $this->assertSame('unpaid-transfer', $store->get('spatbridge.events.etransfer.unmatched.tag'));
        $this->assertSame('', $store->get('spatbridge.events.registration.incomplete.tag'));
        $this->assertSame('offer sent', $store->get('spatbridge.events.waitlist.offered.tag'));
        $this->assertTrue($store->get('spatbridge.events.etransfer.unmatched.create'));
        $this->assertTrue($store->get('spatbridge.events.waitlist.expired.create'));
        $this->assertFalse($store->get('spatbridge.events.etransfer.matched.create'));
        $this->assertSame(5, $store->get('spatbridge.events.mailbox_id'));
        $this->assertSame(7, $store->get('spatbridge.events.note_user_id'));
    }

    public function test_event_settings_round_trip_through_the_router_reads(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request([
            'events_notes' => '1',
            'events_tags' => '1',
            'event_create' => ['etransfer_unmatched' => '1'],
            'events_mailbox_id' => '3',
        ]));

        $read = function (string $key, bool $default) use ($store): bool {
            return OptionBool::from($store->get($key), $default);
        };
        $this->assertTrue($read('spatbridge.events.notes', false));
        $this->assertTrue($read('spatbridge.events.etransfer.unmatched.create', false));
        $this->assertFalse($read('spatbridge.events.waitlist.offered.create', true));
    }

    public function test_note_user_defaults_to_the_first_admin_when_blank(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['events_note_user_id' => '']));

        $this->assertSame(0, $store->get('spatbridge.events.note_user_id'));
    }

    public function test_a_create_switch_needs_a_real_mailbox(): void
    {
        $store = $this->store();

        $this->assertRejected($store, ['event_create' => ['etransfer_unmatched' => '1']], 'events_mailbox_id');
        $this->assertRejected($store, [
            'event_create' => ['etransfer_unmatched' => '1'],
            'events_mailbox_id' => '99',
        ], 'events_mailbox_id');
        $this->assertRejected($store, ['events_mailbox_id' => '99'], 'events_mailbox_id');
        $this->assertSame('https://old.example.com', $store->get('spatbridge.wp_base_url'));
        $this->assertNull($store->get('spatbridge.events.mailbox_id'));
    }

    public function test_no_mailbox_is_fine_when_no_create_switch_is_on(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request(['events_mailbox_id' => '']));

        $this->assertSame(0, $store->get('spatbridge.events.mailbox_id'));
    }

    public function test_tags_are_limited_to_100_word_characters_dashes_and_spaces(): void
    {
        $store = $this->store();

        $field = 'event_tag.etransfer_unmatched';
        $this->assertRejected($store, ['event_tag' => ['etransfer_unmatched' => str_repeat('a', 101)]], $field);
        $this->assertRejected($store, ['event_tag' => ['etransfer_unmatched' => 'bad,tag']], $field);
        $html = ['event_tag' => ['waitlist_offered' => '<b>x</b>']];
        $this->assertRejected($store, $html, 'event_tag.waitlist_offered');
        $this->assertNull($store->get('spatbridge.events.waitlist.offered.tag'));
        $this->assertNull($store->get('spatbridge.events.etransfer.unmatched.tag'));

        $this->controller($store)->save($this->request(['event_tag' => [
            'etransfer_unmatched' => str_repeat('a', 100),
            'waitlist_offered' => 'a-b c_d',
        ]]));
        $this->assertSame(str_repeat('a', 100), $store->get('spatbridge.events.etransfer.unmatched.tag'));
    }

    public function test_a_forged_create_switch_for_a_resolution_event_is_ignored(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request([
            'event_create' => ['etransfer_matched' => '1', 'waitlist_claimed' => '1'],
        ]));

        $this->assertFalse($store->get('spatbridge.events.etransfer.matched.create'));
        $this->assertFalse($store->get('spatbridge.events.waitlist.claimed.create'));
        $this->assertSame(0, $store->get('spatbridge.events.mailbox_id'));
    }

    public function test_unknown_event_types_are_ignored(): void
    {
        $store = $this->store();

        $this->controller($store)->save($this->request([
            'event_tag' => ['bogus_type' => 'x'],
            'event_create' => ['bogus_type' => '1'],
        ]));

        $this->assertNull($store->get('spatbridge.events.bogus.type.tag'));
        $this->assertNull($store->get('spatbridge.events.bogus_type.tag'));
    }

    public function test_connection_test_reports_success_with_version_and_providers(): void
    {
        $client = $this->client(ClientResult::success(200, [
            'spat_version' => '1.1.3',
            'providers' => ['player', 'registration'],
            'modules' => [],
        ], 120));

        $json = $this->controller($this->store())->test($client)->getData(true);

        $this->assertSame([
            'ok' => true,
            'message' => 'Connected. SPAT 1.1.3, 120 ms.',
            'latency_ms' => 120,
            'spat_version' => '1.1.3',
            'providers' => ['player', 'registration'],
        ], $json);
    }

    public function test_connection_test_reports_a_failure_in_plain_words(): void
    {
        $client = $this->client(ClientResult::failure('bad_signature', 403, 80));

        $json = $this->controller($this->store())->test($client)->getData(true);

        $this->assertFalse($json['ok']);
        $this->assertSame(
            'WordPress rejected the signature. Check that both sides use the same secret.',
            $json['message']
        );
        $this->assertNull($json['spat_version']);
        $this->assertSame([], $json['providers']);
    }
}

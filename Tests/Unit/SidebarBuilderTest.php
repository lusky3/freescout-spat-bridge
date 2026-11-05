<?php

namespace Tests\Unit;

use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Features\PanelRow;
use Modules\SpatBridge\Features\RowAction;
use Modules\SpatBridge\Services\ActionPermissions;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\SidebarBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Support\FakeOptionStore;

class SidebarBuilderTest extends TestCase
{
    private function feature(string $key, $panel, array $providerKeys = []): Feature
    {
        return new class ($key, $panel, $providerKeys) implements Feature {
            private $key;
            private $panel;
            private $providerKeys;
            /** @var array<int, array{0: array, 1: string[]}> */
            public $presented = [];

            public function __construct(string $key, $panel, array $providerKeys)
            {
                $this->key = $key;
                $this->panel = $panel;
                $this->providerKeys = $providerKeys;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return $this->key;
            }

            public function providerKeys(): array
            {
                return $this->providerKeys;
            }

            public function present(array $sections, array $emails = []): ?Panel
            {
                $this->presented[] = [$sections, $emails];
                if ($this->panel instanceof \Throwable) {
                    throw $this->panel;
                }

                return $this->panel;
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

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            public $records = [];

            public function log($level, $message, array $context = [])
            {
                $this->records[] = [$level, (string) $message];
            }
        };
    }

    private function ok(): ClientResult
    {
        return ClientResult::success(200, ['sections' => []], 5);
    }

    public function test_failed_result_is_unavailable_not_empty(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build(ClientResult::failure('timeout'), [$this->feature('a', new Panel('a', 'A', []))]);

        $this->assertSame('unavailable', $vm['state']);
        $this->assertSame([], $vm['panels']);
    }

    public function test_features_get_the_sections_and_the_addresses_wordpress_looked_up(): void
    {
        $feature = $this->feature('a', null);
        $result = ClientResult::success(200, [
            'emails' => ['main@example.com', 7, 'other@example.com'],
            'player_ids' => [1],
            'sections' => ['waitlist' => ['entries' => []]],
        ], 5);

        (new SidebarBuilder($this->logger()))->build($result, [$feature]);
        (new SidebarBuilder($this->logger()))->build($this->ok(), [$feature]);

        $this->assertSame([
            [['waitlist' => ['entries' => []]], ['main@example.com', 'other@example.com']],
            [[], []],
        ], $feature->presented);
    }

    public function test_all_features_return_null_is_empty(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->ok(), [$this->feature('a', null), $this->feature('b', null)]);

        $this->assertSame('empty', $vm['state']);
        $this->assertSame([], $vm['panels']);
        $this->assertNull($vm['summary']);
        $this->assertNull($vm['activeTab']);
    }

    public function test_active_tab_is_first_panel_needing_attention(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->ok(), [
            $this->feature('player', new Panel('player', 'Player', [])),
            $this->feature('registration', new Panel('registration', 'Registration', [], null, true)),
        ]);

        $this->assertSame('ok', $vm['state']);
        $this->assertCount(2, $vm['panels']);
        $this->assertSame('registration', $vm['activeTab']);
    }

    public function test_active_tab_defaults_to_first_panel(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->ok(), [
            $this->feature('player', new Panel('player', 'Player', [])),
            $this->feature('registration', new Panel('registration', 'Registration', [])),
        ]);

        $this->assertSame('player', $vm['activeTab']);
    }

    public function test_summary_comes_from_first_panel_that_has_one(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->ok(), [
            $this->feature('a', new Panel('a', 'A', [])),
            $this->feature('b', new Panel('b', 'B', [], 'Ducks, Div 3')),
            $this->feature('c', new Panel('c', 'C', [], 'later')),
        ]);

        $this->assertSame('Ducks, Div 3', $vm['summary']);
    }

    public function test_feature_that_throws_is_skipped_others_still_render(): void
    {
        $logger = $this->logger();
        $builder = new SidebarBuilder($logger);
        $vm = $builder->build($this->ok(), [
            $this->feature('bad', new \RuntimeException('boom')),
            $this->feature('good', new Panel('good', 'Good', [])),
        ]);

        $this->assertSame('ok', $vm['state']);
        $this->assertCount(1, $vm['panels']);
        $this->assertSame('good', $vm['panels'][0]->key());
        $this->assertCount(1, $logger->records);
        $this->assertStringContainsString('[SpatBridge]', $logger->records[0][1]);
        $this->assertStringContainsString('boom', $logger->records[0][1]);
    }

    private function withMissing(array $missing): ClientResult
    {
        return ClientResult::success(200, ['sections' => [], 'missing' => $missing], 5);
    }

    /**
     * @return string[]
     */
    private function lines(Panel $panel): array
    {
        $lines = [];
        foreach ($panel->rows() as $row) {
            $lines = array_merge($lines, $row->lines());
        }

        return $lines;
    }

    public function test_errored_provider_keeps_the_panel_with_a_muted_line(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->withMissing(['player' => 'error']), [
            $this->feature('player', null, ['player', 'registration']),
        ]);

        $this->assertSame('ok', $vm['state']);
        $this->assertCount(1, $vm['panels']);
        $panel = $vm['panels'][0];
        $this->assertSame('player', $panel->key());
        $this->assertSame('player', $panel->label());
        $this->assertSame(['Player info unavailable (WordPress error)'], $this->lines($panel));
        $this->assertSame('', $panel->rows()[0]->title());
        $this->assertSame('muted', $panel->rows()[0]->tone());
        $this->assertNull($panel->rows()[0]->badge());
    }

    public function test_not_registered_provider_line_is_prepended_to_the_feature_rows(): void
    {
        $registrationRow = new PanelRow('Order #5107', ['Last outcome: name rejected'], 'Check', 'bad');
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->withMissing(['player' => 'not_registered', 'waitlist' => 'not_registered']), [
            $this->feature(
                'player',
                new Panel('player', 'Player', [$registrationRow], 'Registration needs attention', true),
                ['player', 'registration']
            ),
        ]);

        $panel = $vm['panels'][0];
        $this->assertCount(2, $panel->rows());
        $this->assertSame(['Player info not available — not enabled in WordPress'], $panel->rows()[0]->lines());
        $this->assertSame($registrationRow, $panel->rows()[1]);
        $this->assertSame('Registration needs attention', $panel->summary());
        $this->assertTrue($panel->attention());
        $this->assertSame('player', $vm['activeTab']);
    }

    public function test_missing_providers_the_feature_does_not_read_change_nothing(): void
    {
        $panel = new Panel('player', 'Player', []);
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->withMissing(['waitlist' => 'not_registered', 'etransfers' => 'error']), [
            $this->feature('player', $panel, ['player', 'registration']),
            $this->feature('other', null, ['player']),
        ]);

        $this->assertSame([$panel], $vm['panels']);
    }

    private function user(int $id, bool $admin)
    {
        return new class ($id, $admin) {
            public $id;
            private $admin;

            public function __construct(int $id, bool $admin)
            {
                $this->id = $id;
                $this->admin = $admin;
            }

            public function isAdmin(): bool
            {
                return $this->admin;
            }
        };
    }

    private function actionPanel(): Panel
    {
        $action = new RowAction('etransfers', 'match', 'Match to order…', ['log_id' => 1]);

        return new Panel('etransfers', 'e-Transfers', [
            new PanelRow('Row A', ['line'], 'Unmatched', 'warn', [$action]),
            new PanelRow('Row B', ['line']),
        ], 'summary', true);
    }

    private function permissions(string $mode): ActionPermissions
    {
        $store = new FakeOptionStore();
        $store->set('spatbridge.features.etransfers.actors', json_encode(['mode' => $mode, 'user_ids' => []]));

        return new ActionPermissions($store);
    }

    public function test_actions_are_stripped_for_a_user_who_may_not_run_them(): void
    {
        $builder = new SidebarBuilder($this->logger(), $this->permissions('admins'));
        $vm = $builder->build(
            $this->ok(),
            [$this->feature('etransfers', $this->actionPanel())],
            $this->user(5, false)
        );

        $panel = $vm['panels'][0];
        $this->assertSame([], $panel->rows()[0]->actions());
        $this->assertSame('Row A', $panel->rows()[0]->title());
        $this->assertSame(['line'], $panel->rows()[0]->lines());
        $this->assertSame('Unmatched', $panel->rows()[0]->badge());
        $this->assertSame('warn', $panel->rows()[0]->tone());
        $this->assertSame('summary', $panel->summary());
        $this->assertTrue($panel->attention());
    }

    public function test_actions_are_kept_for_an_allowed_user(): void
    {
        $builder = new SidebarBuilder($this->logger(), $this->permissions('admins'));
        $vm = $builder->build(
            $this->ok(),
            [$this->feature('etransfers', $this->actionPanel())],
            $this->user(1, true)
        );

        $this->assertCount(1, $vm['panels'][0]->rows()[0]->actions());
        $this->assertSame('match', $vm['panels'][0]->rows()[0]->actions()[0]->action());

        $vm = (new SidebarBuilder($this->logger(), $this->permissions('users')))->build(
            $this->ok(),
            [$this->feature('etransfers', $this->actionPanel())],
            $this->user(5, false)
        );
        $this->assertCount(1, $vm['panels'][0]->rows()[0]->actions());
    }

    public function test_actions_are_stripped_when_permissions_exist_but_no_user_is_given(): void
    {
        $builder = new SidebarBuilder($this->logger(), $this->permissions('users'));
        $vm = $builder->build($this->ok(), [$this->feature('etransfers', $this->actionPanel())]);

        $this->assertSame([], $vm['panels'][0]->rows()[0]->actions());
    }

    public function test_actions_are_untouched_without_permissions_or_user(): void
    {
        $vm = (new SidebarBuilder($this->logger()))->build(
            $this->ok(),
            [$this->feature('etransfers', $this->actionPanel())]
        );

        $this->assertCount(1, $vm['panels'][0]->rows()[0]->actions());
    }

    public function test_actions_are_stripped_when_a_user_is_given_but_no_permissions_exist(): void
    {
        $vm = (new SidebarBuilder($this->logger()))->build(
            $this->ok(),
            [$this->feature('etransfers', $this->actionPanel())],
            $this->user(1, true)
        );

        $this->assertSame([], $vm['panels'][0]->rows()[0]->actions());
    }
}

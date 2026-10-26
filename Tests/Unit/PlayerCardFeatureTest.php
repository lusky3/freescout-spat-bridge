<?php

namespace Tests\Unit;

use Modules\SpatBridge\Features\PlayerCard\PlayerCardFeature;
use PHPUnit\Framework\TestCase;

class PlayerCardFeatureTest extends TestCase
{
    private function player(array $overrides = []): array
    {
        return array_merge([
            'id' => 109432, 'name' => 'Jordan Smith', 'number' => '17', 'positions' => ['Forward'],
            'skill_level' => 'B', 'wp_user_linked' => true,
            'assignments' => [[
                'season_id' => 88, 'season' => 'W2025-26', 'division' => 'Div 3',
                'team_id' => 501, 'team' => 'Ducks', 'current' => true, 'captain' => true,
            ]],
            'next_game' => [
                'event_id' => 7001, 'start' => '2025-10-30T21:45:00-04:00',
                'opponent' => 'Otters', 'venue' => 'Rink 2',
            ],
        ], $overrides);
    }

    private function sections(?array $players = null, ?array $registration = null): array
    {
        $sections = [];
        if ($players !== null) {
            $sections['player'] = ['players' => $players, 'current_season' => 'W2025-26'];
        }
        if ($registration !== null) {
            $sections['registration'] = $registration;
        }

        return $sections;
    }

    private function registration(): array
    {
        return [
            'entries' => [[
                'order_id' => 4821, 'at' => '2026-09-03T14:02:00Z',
                'action' => 'player_created', 'season' => 'W2025-26', 'position' => 'Player',
            ]],
            'attention' => [['order_id' => 5107, 'last_action' => 'name_rejected']],
        ];
    }

    public function test_identity(): void
    {
        $feature = new PlayerCardFeature();

        $this->assertSame('player', $feature->key());
        $this->assertSame('Player', $feature->label());
        $this->assertSame(['player', 'registration'], $feature->providerKeys());
    }

    public function test_one_row_per_player_with_number_name_team_and_badges(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([$this->player()]));

        $this->assertCount(1, $panel->rows());
        $row = $panel->rows()[0];
        $this->assertSame('#17 Jordan Smith (C)', $row->title());
        $this->assertContains('W2025-26 · Div 3 · Ducks', $row->lines());
        $this->assertContains('Forward · Skill B · WP account linked', $row->lines());
        $this->assertSame('player', $panel->key());
        $this->assertSame('Player', $panel->label());
    }

    public function test_two_players_on_one_email_give_two_rows(): void
    {
        $second = $this->player(['id' => 2, 'name' => 'Riley Smith', 'number' => '4', 'next_game' => null]);
        $second['assignments'][0]['captain'] = false;

        $panel = (new PlayerCardFeature())->present($this->sections([$this->player(), $second]));

        $this->assertCount(2, $panel->rows());
        $this->assertSame('#4 Riley Smith', $panel->rows()[1]->title());
    }

    public function test_next_game_line(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([$this->player()]));

        $this->assertContains(
            'Next game Thu Oct 30, 9:45 PM vs Otters · Rink 2',
            $panel->rows()[0]->lines()
        );
    }

    public function test_no_next_game_no_line(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([$this->player(['next_game' => null])]));

        $this->assertCount(2, $panel->rows()[0]->lines());
    }

    public function test_no_current_assignment_says_not_on_a_roster(): void
    {
        $player = $this->player();
        $player['assignments'][0]['current'] = false;

        $panel = (new PlayerCardFeature())->present($this->sections([$player]));

        $this->assertContains('Not on a roster for W2025-26', $panel->rows()[0]->lines());
        $this->assertSame('#17 Jordan Smith', $panel->rows()[0]->title());
        $this->assertTrue($panel->attention());
    }

    public function test_registration_attention_marks_panel(): void
    {
        $panel = (new PlayerCardFeature())->present(
            $this->sections([$this->player()], $this->registration())
        );

        $row = $panel->rows()[1];
        $this->assertSame('Order #5107 · registration not completed', $row->title());
        $this->assertSame('Check', $row->badge());
        $this->assertSame('bad', $row->tone());
        $this->assertSame(['Last outcome: name rejected'], $row->lines());
        $this->assertTrue($panel->attention());
        $this->assertSame('#17 Jordan Smith · Ducks — Registration needs attention', $panel->summary());
    }

    public function test_attention_summary_without_players_is_the_plain_line(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections(null, $this->registration()));

        $this->assertSame('Registration needs attention', $panel->summary());
    }

    public function test_registration_history_lines(): void
    {
        $panel = (new PlayerCardFeature())->present(
            $this->sections([$this->player()], $this->registration())
        );

        $rows = $panel->rows();
        $this->assertSame('Registration history', $rows[2]->title());
        $this->assertSame(['Order #4821 → player created · Sep 3'], $rows[2]->lines());
    }

    public function test_summary_when_fine(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([$this->player()]));

        $this->assertSame('#17 Jordan Smith · Ducks', $panel->summary());
        $this->assertFalse($panel->attention());
    }

    public function test_no_players_and_no_registration_returns_null(): void
    {
        $feature = new PlayerCardFeature();

        $this->assertNull($feature->present([]));
        $this->assertNull($feature->present($this->sections([], ['entries' => [], 'attention' => []])));
    }

    public function test_registration_only_still_gives_a_panel(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections(null, $this->registration()));

        $this->assertCount(2, $panel->rows());
        $this->assertTrue($panel->attention());
    }

    public function test_malformed_values_do_not_throw(): void
    {
        $feature = new PlayerCardFeature();

        $panel = $feature->present([
            'player' => ['players' => ['junk', ['name' => 'Solo', 'assignments' => 'x', 'positions' => 'y',
                'next_game' => ['start' => 'not a date']]]],
            'registration' => ['entries' => 'x', 'attention' => [5, ['order_id' => 1]]],
        ]);

        $this->assertNotNull($panel);
        $this->assertSame('Solo', $panel->rows()[0]->title());
        $this->assertNull($feature->present(['player' => 'x', 'registration' => 7]));
    }

    public function test_has_no_actions_yet(): void
    {
        $feature = new PlayerCardFeature();

        $this->assertSame([], $feature->actionKeys());
        $this->assertNull($feature->previewOverride('any', ['a' => 1]));
        $this->assertSame(['a' => 1], $feature->mapConfirmCodes('any', ['code'], ['a' => 1]));
    }
}

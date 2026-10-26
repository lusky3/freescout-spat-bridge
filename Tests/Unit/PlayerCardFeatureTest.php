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

    private function withEmail(array $players, ?array $registration, string $email): array
    {
        $sections = $this->sections($players, $registration);
        $sections['player']['email'] = $email;

        return $sections;
    }

    private function actionsOf($row): array
    {
        return array_map(function ($action) {
            return [$action->feature(), $action->action(), $action->label(), $action->params()];
        }, $row->actions());
    }

    public function test_action_keys(): void
    {
        $this->assertSame(['change_email', 'rerun'], (new PlayerCardFeature())->actionKeys());
    }

    public function test_the_first_player_row_offers_change_email_with_the_matched_email(): void
    {
        $panel = (new PlayerCardFeature())->present(
            $this->withEmail([$this->player(), $this->player(['name' => 'Second'])], null, 'jordan@example.com')
        );

        $this->assertSame(
            [['player', 'change_email', 'Change email…', ['old_email' => 'jordan@example.com']]],
            $this->actionsOf($panel->rows()[0])
        );
        $this->assertSame([], $panel->rows()[1]->actions());
    }

    public function test_no_change_email_without_an_email(): void
    {
        $feature = new PlayerCardFeature();

        foreach ([null, '', '  ', ['x']] as $email) {
            $sections = $this->sections([$this->player()], null);
            if ($email !== null) {
                $sections['player']['email'] = $email;
            }
            $actions = $this->actionsOf($feature->present($sections)->rows()[0]);
            $this->assertSame([], $actions);
        }
    }

    public function test_each_attention_row_offers_rerun_with_its_order_id(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([], [
            'attention' => [
                ['order_id' => 5107, 'last_action' => 'name_rejected'],
                ['order_id' => '5108', 'last_action' => 'failed'],
            ],
        ]));

        $this->assertSame(
            [['player', 'rerun', 'Re-run registration', ['order_id' => 5107]]],
            $this->actionsOf($panel->rows()[0])
        );
        $this->assertSame(
            [['player', 'rerun', 'Re-run registration', ['order_id' => 5108]]],
            $this->actionsOf($panel->rows()[1])
        );
    }

    public function test_an_attention_row_without_an_order_id_has_no_rerun(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([], [
            'attention' => [['last_action' => 'failed'], ['order_id' => 0], ['order_id' => 'abc'], ['order_id' => -4]],
        ]));

        foreach ($panel->rows() as $row) {
            $this->assertSame([], $row->actions());
        }
    }

    public function test_history_rows_have_no_actions(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([], $this->registration()));

        $this->assertSame([], $panel->rows()[count($panel->rows()) - 1]->actions());
    }

    public function test_change_email_without_a_new_email_shows_the_form(): void
    {
        $override = (new PlayerCardFeature())->previewOverride('change_email', ['old_email' => 'a@example.com']);

        $this->assertSame('spatbridge::action.change_email_form', $override['view']);
        $this->assertSame('a@example.com', $override['data']['oldEmail']);
    }

    public function test_change_email_with_a_valid_new_email_goes_to_the_dry_run(): void
    {
        $this->assertNull((new PlayerCardFeature())->previewOverride(
            'change_email',
            ['old_email' => 'a@example.com', 'new_email' => 'b@example.com']
        ));
    }

    public function test_change_email_validates_the_new_email_before_any_wordpress_call(): void
    {
        $feature = new PlayerCardFeature();

        foreach (['not an email', 'a@', '  ', 'A@Example.com', ' a@example.com '] as $new) {
            $params = ['old_email' => 'a@example.com', 'new_email' => $new];
            $override = $feature->previewOverride('change_email', $params);
            $this->assertSame('spatbridge::action.error', $override['view'], var_export($new, true));
            $this->assertNotSame('', $override['data']['message']);
        }
        $override = $feature->previewOverride('change_email', ['old_email' => 'a@example.com', 'new_email' => ['x']]);
        $this->assertSame('spatbridge::action.error', $override['view']);
    }

    public function test_rerun_has_no_form(): void
    {
        $feature = new PlayerCardFeature();

        $this->assertNull($feature->previewOverride('rerun', ['order_id' => 5107]));
        $this->assertNull($feature->previewOverride('other', []));
    }

    public function test_confirm_codes_change_nothing(): void
    {
        $this->assertSame(['a' => 1], (new PlayerCardFeature())->mapConfirmCodes('change_email', ['code'], ['a' => 1]));
    }

    public function test_change_email_is_only_allowed_for_the_ticket_customers_own_address(): void
    {
        $feature = new PlayerCardFeature();
        $mine = ['Jordan@Example.com', 'other@example.com'];

        $this->assertNull($feature->guardParams('change_email', ['old_email' => 'jordan@example.COM'], $mine));
        $this->assertNull($feature->guardParams('change_email', ['old_email' => ' other@example.com '], $mine));

        foreach ([['old_email' => 'victim@example.com'], ['old_email' => ''], [], ['old_email' => ['x']]] as $params) {
            $this->assertNotNull($feature->guardParams('change_email', $params + ['new_email' => 'a@b.co'], $mine));
        }
        $this->assertNotNull($feature->guardParams('change_email', ['old_email' => 'jordan@example.com'], []));
    }

    public function test_the_guard_leaves_other_actions_alone(): void
    {
        $this->assertNull((new PlayerCardFeature())->guardParams('rerun', ['order_id' => 5107], []));
    }

    public function test_change_email_note_follow_up_explains_the_card_follows_the_ticket(): void
    {
        $feature = new PlayerCardFeature();

        $this->assertSame(
            "The ARL League card follows the ticket's email address. To see it here, "
            . "update the customer's email in FreeScout to the new address.",
            $feature->noteFollowUp('change_email', ['new_email' => 'new@example.com'])
        );
        $this->assertNull($feature->noteFollowUp('rerun', ['order_id' => 5107]));
    }
}

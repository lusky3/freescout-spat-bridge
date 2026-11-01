<?php

namespace Tests\Unit;

use Modules\SpatBridge\Features\PlayerCard\PlayerCardFeature;
use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\Support\RegistrationOutcome;
use PHPUnit\Framework\TestCase;

class RegistrationOutcomeTest extends TestCase
{
    private const CONFLICT = 'name match conflicts with the account or email on the record, needs manual review';
    private const UNVERIFIED = 'matched by name only, not verified';
    private const LINK_REFUSED = 'account link refused, the player belongs to another account';

    private function sections(array $registration): array
    {
        return [
            'player' => [
                'players' => [[
                    'id' => 109432, 'name' => 'Jordan Smith', 'number' => '17', 'positions' => ['Forward'],
                    'skill_level' => 'B', 'wp_user_linked' => true, 'assignments' => [], 'next_game' => null,
                ]],
                'current_season' => 'W2025-26',
            ],
            'registration' => $registration,
        ];
    }

    public function test_identity_outcomes_get_plain_language_labels(): void
    {
        $this->assertSame(self::CONFLICT, RegistrationOutcome::label('player_found_by_name_identity_conflict'));
        $this->assertSame(self::UNVERIFIED, RegistrationOutcome::label('player_found_by_name_unverified'));
        $this->assertSame(self::UNVERIFIED, RegistrationOutcome::label('player_found_by_normalized_name_unverified'));
        $this->assertSame(self::LINK_REFUSED, RegistrationOutcome::label('user_link_refused_identity_conflict'));
    }

    public function test_other_codes_keep_the_humanised_fallback(): void
    {
        $this->assertSame('name rejected', RegistrationOutcome::label('name_rejected'));
        $this->assertSame('player created', RegistrationOutcome::label('player_created'));
        $this->assertSame('some new thing', RegistrationOutcome::label('some_new_thing'));
        $this->assertSame('', RegistrationOutcome::label(''));
    }

    public function test_an_identity_conflict_in_the_attention_list_reads_in_plain_language(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([
            'entries' => [],
            'attention' => [['order_id' => 5107, 'last_action' => 'player_found_by_name_identity_conflict']],
        ]));

        $row = $panel->rows()[1];
        $this->assertSame('Order #5107 · registration not completed', $row->title());
        $this->assertSame('bad', $row->tone());
        $this->assertSame(['Last outcome: ' . self::CONFLICT], $row->lines());
        $this->assertTrue($panel->attention());
    }

    public function test_history_lines_use_the_labels(): void
    {
        $panel = (new PlayerCardFeature())->present($this->sections([
            'entries' => [[
                'order_id' => 4821, 'at' => '2026-09-03T14:02:00Z',
                'action' => 'player_found_by_name_unverified', 'season' => 'W2025-26', 'position' => 'Player',
            ]],
            'attention' => [],
        ]));

        $rows = $panel->rows();
        $this->assertSame('Registration history', $rows[1]->title());
        $this->assertSame(['Order #4821 → ' . self::UNVERIFIED . ' · Sep 3'], $rows[1]->lines());
    }

    public function test_the_registration_event_note_uses_the_labels(): void
    {
        $note = (new EventCatalog())->noteHtml('registration.incomplete', [
            'order_id' => 5107,
            'outcome' => 'player_found_by_name_identity_conflict',
        ]);

        $this->assertStringContainsString(
            'Registration for order #5107 did not complete: ' . self::CONFLICT . '.',
            $note
        );
    }
}

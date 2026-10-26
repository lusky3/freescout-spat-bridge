<?php

namespace Tests\Unit;

use Modules\SpatBridge\Features\Waitlist\WaitlistFeature;
use PHPUnit\Framework\TestCase;

class WaitlistFeatureTest extends TestCase
{
    private const NO_TARGET = 'No target product set (set it in WordPress before offering)';

    private function entry(array $overrides = []): array
    {
        return array_merge([
            'id' => 7, 'season' => 'S2026', 'position' => 'goalie', 'status' => 'queued',
            'created_at' => '2026-08-03T12:00:00Z', 'offered_at' => null, 'expires_at' => null,
            'has_target' => true,
        ], $overrides);
    }

    private function present(array $entries)
    {
        return (new WaitlistFeature())->present(['waitlist' => ['entries' => $entries]]);
    }

    private function row(array $overrides = [])
    {
        return $this->present([$this->entry($overrides)])->rows()[0];
    }

    private function labels($row): array
    {
        return array_map(function ($action) {
            return $action->label();
        }, $row->actions());
    }

    public function test_identity(): void
    {
        $feature = new WaitlistFeature();

        $this->assertSame('waitlist', $feature->key());
        $this->assertSame('Waitlist', $feature->label());
        $this->assertSame(['waitlist'], $feature->providerKeys());
        $this->assertSame(['offer', 'reoffer', 'withdraw', 'remove'], $feature->actionKeys());
        $this->assertSame([], $feature->queryKeys());
    }

    public function test_no_entries_means_no_panel(): void
    {
        $this->assertNull($this->present([]));
        $this->assertNull((new WaitlistFeature())->present([]));
    }

    public function test_a_queued_row(): void
    {
        $row = $this->row();

        $this->assertSame('S2026 · Goalie', $row->title());
        $this->assertSame('On waitlist', $row->badge());
        $this->assertSame('muted', $row->tone());
        $this->assertSame(['On waitlist since Aug 3'], $row->lines());
    }

    private function in(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $seconds);
    }

    private function offered(string $expiresAt)
    {
        return $this->row(['status' => 'offered', 'position' => 'player', 'expires_at' => $expiresAt]);
    }

    public function test_an_offered_row(): void
    {
        $row = $this->offered($this->in(2 * 3600 + 60));

        $this->assertSame('S2026 · Player', $row->title());
        $this->assertSame('Offer sent', $row->badge());
        $this->assertSame('warn', $row->tone());
        $this->assertSame(['Expires in 2 hours'], $row->lines());
    }

    public function test_the_live_offer_line_matches_the_splm_waitlist_thresholds(): void
    {
        $this->assertSame(['Expires in less than an hour'], $this->offered($this->in(600))->lines());
        $this->assertSame(['Expires in 1 hour'], $this->offered($this->in(3600 + 60))->lines());
        $this->assertSame(['Expires in 2 hours'], $this->offered($this->in(7200 + 60))->lines());
        $this->assertSame(['Expires in 23 hours'], $this->offered($this->in(23 * 3600 + 60))->lines());
        $this->assertSame(['Expires in 1 day'], $this->offered($this->in(24 * 3600 + 60))->lines());
        $this->assertSame(['Expires in 2 days'], $this->offered($this->in(2 * 86400 + 60))->lines());
    }

    public function test_an_offered_row_without_a_deadline_falls_back_to_the_since_line(): void
    {
        foreach ([null, ''] as $missing) {
            $row = $this->row(['status' => 'offered', 'expires_at' => $missing]);

            $this->assertSame('Offer sent', $row->badge());
            $this->assertSame('warn', $row->tone());
            $this->assertSame(['On waitlist since Aug 3'], $row->lines());
        }
    }

    public function test_an_offer_past_its_deadline_reads_as_expired_but_keeps_its_actions(): void
    {
        $panel = $this->present([$this->entry(['status' => 'offered', 'expires_at' => $this->in(-3600)])]);
        $row = $panel->rows()[0];

        $this->assertSame('Offer expired', $row->badge());
        $this->assertSame('bad', $row->tone());
        $this->assertSame(['On waitlist since Aug 3'], $row->lines());
        $this->assertSame(['Re-offer…', 'Withdraw offer'], $this->labels($row));
        $this->assertTrue($panel->attention());
        $this->assertStringStartsWith('Offered S2026 Goalie, expires ', $panel->summary());
    }

    public function test_a_deadline_of_exactly_now_is_expired(): void
    {
        $row = $this->offered($this->in(0));

        $this->assertSame('Offer expired', $row->badge());
        $this->assertSame('bad', $row->tone());
    }

    public function test_an_unparseable_deadline_shows_the_raw_text_as_sent(): void
    {
        $panel = $this->present([$this->entry(['status' => 'offered', 'expires_at' => 'next <b>week'])]);
        $row = $panel->rows()[0];

        $this->assertSame('Offer sent', $row->badge());
        $this->assertSame('warn', $row->tone());
        $this->assertSame(['Expires next <b>week'], $row->lines());
        $this->assertFalse($panel->attention());
    }

    public function test_an_expired_row(): void
    {
        $row = $this->row(['status' => 'expired', 'expires_at' => '2026-10-27T18:00:00Z']);

        $this->assertSame('Offer expired', $row->badge());
        $this->assertSame('bad', $row->tone());
        $this->assertSame(['On waitlist since Aug 3'], $row->lines());
    }

    public function test_a_claimed_row_has_no_actions(): void
    {
        $row = $this->row(['status' => 'claimed']);

        $this->assertSame('Accepted', $row->badge());
        $this->assertSame('ok', $row->tone());
        $this->assertSame(['On waitlist since Aug 3'], $row->lines());
        $this->assertSame([], $row->actions());
    }

    public function test_an_unknown_status_looks_queued_but_offers_no_actions(): void
    {
        $row = $this->row(['status' => 'weird']);

        $this->assertSame('On waitlist', $row->badge());
        $this->assertSame('muted', $row->tone());
        $this->assertSame([], $row->actions());
    }

    public function test_action_placement(): void
    {
        $this->assertSame(['Offer…', 'Remove from waitlist'], $this->labels($this->row()));
        $this->assertSame(['Offer…'], $this->labels($this->row(['status' => 'expired'])));
        $this->assertSame(
            ['Re-offer…', 'Withdraw offer'],
            $this->labels($this->row(['status' => 'offered']))
        );
    }

    public function test_actions_carry_the_entry_id(): void
    {
        $actions = $this->row()->actions();

        $this->assertSame('waitlist', $actions[0]->feature());
        $this->assertSame('offer', $actions[0]->action());
        $this->assertSame(['entry_id' => 7], $actions[0]->params());
        $this->assertSame('remove', $actions[1]->action());
        $this->assertSame(['entry_id' => 7], $actions[1]->params());

        $offered = $this->row(['status' => 'offered', 'id' => 9])->actions();
        $this->assertSame(['reoffer', 'withdraw'], [$offered[0]->action(), $offered[1]->action()]);
        $this->assertSame(['entry_id' => 9], $offered[1]->params());
    }

    public function test_without_a_target_there_is_a_warning_line_and_no_offer(): void
    {
        $queued = $this->row(['has_target' => false]);
        $this->assertSame(['On waitlist since Aug 3', self::NO_TARGET], $queued->lines());
        $this->assertSame(['Remove from waitlist'], $this->labels($queued));

        $expired = $this->row(['status' => 'expired', 'has_target' => false]);
        $this->assertContains(self::NO_TARGET, $expired->lines());
        $this->assertSame([], $expired->actions());

        $offered = $this->row([
            'status' => 'offered', 'has_target' => false, 'expires_at' => $this->in(2 * 86400 + 60),
        ]);
        $this->assertSame(['Expires in 2 days'], $offered->lines());
        $this->assertSame(['Re-offer…', 'Withdraw offer'], $this->labels($offered));
    }

    public function test_summary_for_an_offer(): void
    {
        $panel = $this->present([
            $this->entry(['id' => 1, 'season' => 'S2025', 'status' => 'claimed']),
            $this->entry(['status' => 'offered', 'expires_at' => '2099-10-27T18:00:00Z']),
        ]);

        $this->assertSame('Offered S2026 Goalie, expires Oct 27, 6:00 PM UTC', $panel->summary());
        $this->assertFalse($panel->attention());
    }

    public function test_dates_are_shown_in_the_viewing_agents_timezone_with_its_abbreviation(): void
    {
        $feature = new WaitlistFeature(function () {
            return new \DateTimeZone('America/Toronto');
        });
        $panel = $feature->present(['waitlist' => ['entries' => [
            $this->entry(['id' => 1, 'status' => 'queued', 'created_at' => '2026-08-04T02:00:00Z']),
            $this->entry(['id' => 2, 'status' => 'offered', 'expires_at' => '2030-10-27T18:00:00Z']),
        ]]]);

        $this->assertSame('Offered S2026 Goalie, expires Oct 27, 2:00 PM EDT', $panel->summary());
        $lines = array_merge(...array_map(function ($row) {
            return $row->lines();
        }, $panel->rows()));
        $this->assertContains('On waitlist since Aug 3', $lines);
    }

    public function test_summary_without_an_offer(): void
    {
        $panel = $this->present([$this->entry()]);

        $this->assertSame('On the S2026 waitlist', $panel->summary());
        $this->assertFalse($panel->attention());
    }

    public function test_an_expired_offer_needs_attention(): void
    {
        $panel = $this->present([$this->entry(['status' => 'expired'])]);

        $this->assertTrue($panel->attention());
        $this->assertSame('On the S2026 waitlist', $panel->summary());
    }

    public function test_panel_identity(): void
    {
        $panel = $this->present([$this->entry()]);

        $this->assertSame('waitlist', $panel->key());
        $this->assertSame('Waitlist', $panel->label());
    }

    public function test_the_offer_form_shows_until_hours_are_known(): void
    {
        $feature = new WaitlistFeature();

        foreach (['offer', 'reoffer'] as $action) {
            $override = $feature->previewOverride($action, ['entry_id' => 7]);
            $this->assertSame('spatbridge::action.waitlist_offer_form', $override['view']);
            $this->assertSame(48, $override['data']['defaultHours']);

            $this->assertNull($feature->previewOverride($action, ['entry_id' => 7, 'hours' => 24]));
        }
    }

    public function test_no_form_for_other_actions(): void
    {
        $feature = new WaitlistFeature();

        $this->assertNull($feature->previewOverride('withdraw', ['entry_id' => 7]));
        $this->assertNull($feature->previewOverride('remove', ['entry_id' => 7]));
    }

    public function test_confirm_codes_change_nothing(): void
    {
        $params = ['entry_id' => 7, 'hours' => 48];

        $this->assertSame($params, (new WaitlistFeature())->mapConfirmCodes('offer', ['x'], $params));
    }

    public function test_malformed_data_does_not_throw(): void
    {
        $feature = new WaitlistFeature();

        $this->assertNull($feature->present(['waitlist' => 'nope']));
        $this->assertNull($feature->present(['waitlist' => ['entries' => 'nope']]));
        $this->assertNull($feature->present(['waitlist' => ['entries' => ['x', 5, null]]]));

        $panel = $this->present([[
            'id' => 'abc', 'season' => ['x'], 'position' => 3, 'status' => [],
            'created_at' => 'garbage', 'expires_at' => 'garbage', 'has_target' => 'yes',
        ]]);
        $this->assertNotNull($panel);
        $row = $panel->rows()[0];
        $this->assertSame('Waitlist', $row->title());
        $this->assertSame([], $row->lines());
        $this->assertSame([], $row->actions());

        $offered = $this->row(['status' => 'offered', 'expires_at' => 'garbage', 'created_at' => null]);
        $this->assertSame(['Expires garbage'], $offered->lines());
        $this->assertNotNull($feature->previewOverride('offer', []));
    }
}

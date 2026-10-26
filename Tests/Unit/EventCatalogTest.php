<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\EventCatalog;
use PHPUnit\Framework\TestCase;

class EventCatalogTest extends TestCase
{
    private const FOOTER = '<p><small>via ARL League (event)</small></p>';

    /** @var EventCatalog */
    private $catalog;

    protected function setUp(): void
    {
        $this->catalog = new EventCatalog();
    }

    public function test_types_are_the_seven_wire_types(): void
    {
        $this->assertSame([
            'etransfer.unmatched',
            'etransfer.matched',
            'registration.incomplete',
            'registration.reversed',
            'waitlist.offered',
            'waitlist.expired',
            'waitlist.claimed',
        ], $this->catalog->types());
        $this->assertTrue($this->catalog->has('waitlist.claimed'));
        $this->assertFalse($this->catalog->has('order.created'));
    }

    public function test_default_tags(): void
    {
        $this->assertSame(['add' => 'payment-unmatched'], $this->catalog->defaultTag('etransfer.unmatched'));
        $this->assertSame(['remove' => 'payment-unmatched'], $this->catalog->defaultTag('etransfer.matched'));
        $this->assertSame(['add' => 'registration-issue'], $this->catalog->defaultTag('registration.incomplete'));
        $this->assertSame(['add' => 'registration-issue'], $this->catalog->defaultTag('registration.reversed'));
        $this->assertSame(['add' => 'waitlist-offered'], $this->catalog->defaultTag('waitlist.offered'));
        $this->assertSame(
            ['add' => 'waitlist-expired', 'remove' => 'waitlist-offered'],
            $this->catalog->defaultTag('waitlist.expired')
        );
        $this->assertSame(['remove' => 'waitlist-offered'], $this->catalog->defaultTag('waitlist.claimed'));
        $this->assertNull($this->catalog->defaultTag('order.created'));
    }

    public function test_removed_tags_belong_to_the_type_that_adds_them(): void
    {
        $this->assertSame('etransfer.unmatched', $this->catalog->removesTagOf('etransfer.matched'));
        $this->assertSame('waitlist.offered', $this->catalog->removesTagOf('waitlist.expired'));
        $this->assertSame('waitlist.offered', $this->catalog->removesTagOf('waitlist.claimed'));
        $this->assertNull($this->catalog->removesTagOf('etransfer.unmatched'));
    }

    public function test_unmatched_note_and_subject(): void
    {
        $data = ['log_id' => 12, 'amount' => '325.00', 'from_name' => 'Pat Smith', 'reference' => 'CA8kQ2'];

        $this->assertSame(
            '<p>Unmatched e-Transfer: $325.00 from Pat Smith (ref CA8kQ2).</p>' . self::FOOTER,
            $this->catalog->noteHtml('etransfer.unmatched', $data)
        );
        $this->assertSame(
            'Unmatched e-Transfer: Pat Smith, $325.00',
            $this->catalog->subject('etransfer.unmatched', $data)
        );
    }

    public function test_unmatched_without_sender_or_reference(): void
    {
        $data = ['amount' => 50, 'from_name' => '', 'reference' => ''];

        $this->assertSame(
            '<p>Unmatched e-Transfer: $50.00 from Unknown sender.</p>' . self::FOOTER,
            $this->catalog->noteHtml('etransfer.unmatched', $data)
        );
        $this->assertSame(
            'Unmatched e-Transfer: Unknown sender, $50.00',
            $this->catalog->subject('etransfer.unmatched', $data)
        );
    }

    public function test_matched_note_and_subject(): void
    {
        $data = ['order_id' => 5107, 'amount' => '325.00', 'reference' => 'CA8kQ2', 'manual' => false];

        $this->assertSame(
            '<p>e-Transfer matched to order #5107: $325.00 (ref CA8kQ2).</p>' . self::FOOTER,
            $this->catalog->noteHtml('etransfer.matched', $data)
        );
        $this->assertSame('e-Transfer matched: order #5107', $this->catalog->subject('etransfer.matched', $data));

        $data['manual'] = true;
        $this->assertSame(
            '<p>e-Transfer matched to order #5107 by hand: $325.00 (ref CA8kQ2).</p>' . self::FOOTER,
            $this->catalog->noteHtml('etransfer.matched', $data)
        );
    }

    public function test_registration_notes_and_subjects(): void
    {
        $incomplete = ['order_id' => 5107, 'outcome' => 'name_rejected'];
        $this->assertSame(
            '<p>Registration for order #5107 did not complete: name rejected.</p>' . self::FOOTER,
            $this->catalog->noteHtml('registration.incomplete', $incomplete)
        );
        $this->assertSame(
            'Registration not completed: order #5107',
            $this->catalog->subject('registration.incomplete', $incomplete)
        );

        $this->assertSame(
            '<p>Registration for order #5107 was reversed.</p>' . self::FOOTER,
            $this->catalog->noteHtml('registration.reversed', ['order_id' => 5107])
        );
        $this->assertSame(
            'Registration reversed: order #5107',
            $this->catalog->subject('registration.reversed', ['order_id' => 5107])
        );
    }

    public function test_waitlist_notes_and_subjects(): void
    {
        $offered = [
            'entry_id' => 3,
            'season' => 'S2026',
            'position' => 'Goalie',
            'expires_at' => '2026-10-27T18:00:00Z',
        ];
        $this->assertSame(
            '<p>Waitlist offer sent for S2026 (Goalie), expires Oct 27, 6:00 PM UTC.</p>' . self::FOOTER,
            $this->catalog->noteHtml('waitlist.offered', $offered)
        );
        $this->assertSame('Waitlist offer: S2026 Goalie', $this->catalog->subject('waitlist.offered', $offered));

        $expired = ['entry_id' => 3, 'season' => 'S2026', 'position' => 'Goalie'];
        $this->assertSame(
            '<p>Waitlist offer for S2026 (Goalie) expired.</p>' . self::FOOTER,
            $this->catalog->noteHtml('waitlist.expired', $expired)
        );
        $this->assertSame(
            'Waitlist offer expired: S2026 Goalie',
            $this->catalog->subject('waitlist.expired', $expired)
        );

        $claimed = ['entry_id' => 3, 'season' => 'S2026', 'position' => 'Goalie', 'order_id' => 5110];
        $this->assertSame(
            '<p>Waitlist offer for S2026 (Goalie) claimed with order #5110.</p>' . self::FOOTER,
            $this->catalog->noteHtml('waitlist.claimed', $claimed)
        );
        $this->assertSame(
            'Waitlist offer claimed: S2026 Goalie',
            $this->catalog->subject('waitlist.claimed', $claimed)
        );
    }

    public function test_waitlist_expiry_in_mysql_format_is_read_as_utc_and_unreadable_values_are_shown_raw(): void
    {
        $this->assertStringContainsString(
            'expires Oct 27, 6:00 PM UTC.',
            $this->catalog->noteHtml(
                'waitlist.offered',
                ['season' => 'S2026', 'position' => 'Goalie', 'expires_at' => '2026-10-27 18:00:00']
            )
        );
        $this->assertStringContainsString(
            'expires next week.',
            $this->catalog->noteHtml('waitlist.offered', ['season' => 'S2026', 'expires_at' => 'next week'])
        );
        $this->assertStringContainsString(
            '<p>Waitlist offer sent for S2026.</p>',
            $this->catalog->noteHtml('waitlist.offered', ['season' => 'S2026', 'position' => ''])
        );
    }

    public function test_everything_from_wordpress_is_escaped_in_notes(): void
    {
        $html = $this->catalog->noteHtml('etransfer.unmatched', [
            'amount' => '<b>5</b>',
            'from_name' => '<script>alert(1)</script>',
            'reference' => '"&',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('from &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('(ref &quot;&amp;)', $html);
    }

    public function test_subjects_are_plain_text_and_ignore_non_scalar_data(): void
    {
        // FreeScout escapes subjects when it shows them; escaping here would double it.
        $this->assertSame(
            'Unmatched e-Transfer: Pat & Sam, $1.50',
            $this->catalog->subject('etransfer.unmatched', ['from_name' => 'Pat & Sam', 'amount' => '1.5'])
        );
        $this->assertSame(
            'Unmatched e-Transfer: Unknown sender, $2.00',
            $this->catalog->subject('etransfer.unmatched', ['from_name' => ['x'], 'amount' => '2'])
        );
    }
}

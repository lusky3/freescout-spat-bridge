<?php

namespace Tests\Unit;

use Modules\SpatBridge\Http\Controllers\EventLogController;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeEventStore;

class EventLogTest extends TestCase
{
    private function row(string $type = 'etransfer.unmatched', array $over = []): array
    {
        return array_merge([
            'type' => $type,
            'email' => 'jordan.smith@example.com',
            'outcome' => 'noted',
            'conversation_ids' => '12,40',
            'detail' => '',
            'created_at' => '2026-10-25 14:02:00',
        ], $over);
    }

    public function test_it_asks_for_the_latest_100_events(): void
    {
        $store = new FakeEventStore();

        (new EventLogController($store))->rows();

        $this->assertSame([100], $store->latestLimits);
    }

    public function test_it_lists_newest_first_and_caps_at_100(): void
    {
        $store = new FakeEventStore();
        for ($i = 1; $i <= 130; $i++) {
            $store->log[$i] = $this->row('etransfer.unmatched', ['email' => 'p' . $i . '@example.com']);
        }

        $rows = (new EventLogController($store))->rows();

        $this->assertCount(100, $rows);
        $this->assertSame('p130@example.com', $rows[0]['email']);
        $this->assertSame('p31@example.com', $rows[99]['email']);
    }

    public function test_rows_carry_labels_ticket_ids_and_time(): void
    {
        $store = new FakeEventStore();
        $store->log[1] = $this->row('waitlist.offered', [
            'outcome' => 'created_tag_skipped',
            'detail' => 'Mailbox 9 <b>gone</b>',
        ]);

        $row = (new EventLogController($store))->rows()[0];

        $this->assertSame('Waitlist offer sent', $row['type']);
        $this->assertSame('Ticket created, tag skipped', $row['outcome']);
        $this->assertSame([12, 40], $row['conversationIds']);
        $this->assertSame('2026-10-25 14:02:00', $row['time']);
        $this->assertSame('Mailbox 9 <b>gone</b>', $row['detail']);
    }

    public function test_bad_conversation_id_lists_are_tolerated(): void
    {
        $store = new FakeEventStore();
        $store->log[1] = $this->row('etransfer.matched', ['conversation_ids' => '']);
        $store->log[2] = $this->row('etransfer.matched', ['conversation_ids' => '7,,x,0,9']);

        $rows = (new EventLogController($store))->rows();

        $this->assertSame([7, 9], $rows[0]['conversationIds']);
        $this->assertSame([], $rows[1]['conversationIds']);
    }

    public function test_a_failing_store_gives_a_notice_instead_of_an_error(): void
    {
        $store = new FakeEventStore();
        $store->latestFails = new \RuntimeException('no such table');

        $data = (new EventLogController($store))->data();

        $this->assertSame(['events' => [], 'tableMissing' => true], $data);
    }

    public function test_a_working_store_is_not_flagged(): void
    {
        $data = (new EventLogController(new FakeEventStore()))->data();

        $this->assertFalse($data['tableMissing']);
    }

    public function test_the_log_view_escapes_everything_and_links_tickets(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Resources/views/events/log.blade.php');

        $this->assertStringNotContainsString('{!!', $source);
        $this->assertStringContainsString("route('conversations.view'", $source);
        foreach (['time', 'type', 'email', 'outcome', 'detail'] as $field) {
            $this->assertStringContainsString("{{ \$event['" . $field . "'] }}", $source);
        }
    }

    public function test_the_route_is_in_the_admin_group(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/Http/routes.php');

        $this->assertMatchesRegularExpression(
            "~'roles' => \['admin'\].*?Route::get\('/app-settings/spatbridge/events'"
                . ".*?EventLogController@index.*?\}\);~s",
            $routes
        );
    }
}

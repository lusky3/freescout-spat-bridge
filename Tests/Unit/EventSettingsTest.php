<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\EventSettings;
use Modules\SpatBridge\Services\TagService;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeEventStore;
use Tests\Support\FakeOptionStore;
use Tests\Support\FakeTag;

class EventSettingsTest extends TestCase
{
    /** @var FakeOptionStore */
    private $options;
    /** @var FakeEventStore */
    private $store;

    protected function setUp(): void
    {
        $this->options = new FakeOptionStore();
        $this->store = new FakeEventStore();
    }

    private function settings(bool $tagsActive = true): EventSettings
    {
        $tags = new TagService(function () use ($tagsActive) {
            return $tagsActive;
        }, FakeTag::class);

        return new EventSettings($this->options, new EventCatalog(), $tags, $this->store);
    }

    private function type(array $model, string $type): array
    {
        foreach ($model['types'] as $row) {
            if ($row['type'] === $type) {
                return $row;
            }
        }
        $this->fail('no row for ' . $type);
    }

    public function test_defaults_match_what_the_router_does_with_nothing_saved(): void
    {
        $model = $this->settings()->build();

        $this->assertTrue($model['notes']);
        $this->assertTrue($model['tags']);
        $this->assertSame(0, $model['mailboxId']);
        $this->assertSame(0, $model['noteUserId']);
        $this->assertCount(7, $model['types']);
        $row = $this->type($model, 'etransfer.unmatched');
        $this->assertSame('e-Transfer unmatched', $row['label']);
        $this->assertSame('payment-unmatched', $row['tag']);
        $this->assertFalse($row['create']);
        $this->assertSame('etransfer_unmatched', $row['key']);
        $this->assertSame('', $this->type($model, 'etransfer.matched')['tag']);
    }

    public function test_saved_values_read_back_through_a_freescout_like_store(): void
    {
        $this->options->set('spatbridge.events.notes', false);
        $this->options->set('spatbridge.events.tags', false);
        $this->options->set('spatbridge.events.waitlist.offered.tag', 'offer sent');
        $this->options->set('spatbridge.events.registration.reversed.tag', '');
        $this->options->set('spatbridge.events.waitlist.expired.create', true);
        $this->options->set('spatbridge.events.mailbox_id', 4);
        $this->options->set('spatbridge.events.note_user_id', 9);

        $model = $this->settings()->build();

        $this->assertFalse($model['notes']);
        $this->assertFalse($model['tags']);
        $this->assertSame('offer sent', $this->type($model, 'waitlist.offered')['tag']);
        $this->assertSame('', $this->type($model, 'registration.reversed')['tag']);
        $this->assertTrue($this->type($model, 'waitlist.expired')['create']);
        $this->assertFalse($this->type($model, 'waitlist.offered')['create']);
        $this->assertSame(4, $model['mailboxId']);
        $this->assertSame(9, $model['noteUserId']);
    }

    public function test_tags_module_state_is_reported(): void
    {
        $this->assertTrue($this->settings(true)->build()['tagsAvailable']);
        $this->assertFalse($this->settings(false)->build()['tagsAvailable']);
    }

    public function test_last_event_is_null_until_one_arrives_then_the_newest(): void
    {
        $this->assertNull($this->settings()->build()['lastEvent']);

        $this->store->log[1] = [
            'type' => 'etransfer.matched', 'email' => 'a@example.com', 'outcome' => 'noted',
            'conversation_ids' => '', 'detail' => '', 'created_at' => '2026-10-24 10:00:00',
        ];
        $this->store->log[2] = [
            'type' => 'etransfer.unmatched', 'email' => 'b@example.com', 'outcome' => 'created_tag_skipped',
            'conversation_ids' => '5', 'detail' => '', 'created_at' => '2026-10-25 14:02:00',
        ];

        $last = $this->settings()->build()['lastEvent'];

        $this->assertSame([
            'time' => '2026-10-25 14:02:00',
            'type' => 'e-Transfer unmatched',
            'email' => 'b@example.com',
            'outcome' => 'Ticket created, tag skipped',
        ], $last);
    }
}

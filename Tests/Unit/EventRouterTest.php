<?php

namespace Tests\Unit;

use App\Thread;
use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\EventRouter;
use Modules\SpatBridge\Services\EventStoreInterface;
use Modules\SpatBridge\Services\NoteWriter;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Modules\SpatBridge\Services\TagService;
use Modules\SpatBridge\Services\TicketCreator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\TestLogger;
use Tests\Support\FakeCustomer;
use Tests\Support\FakeEventStore;
use Tests\Support\FakeOptionStore;
use Tests\Support\FakeTag;

class EventRouterTest extends TestCase
{
    private const EMAIL = 'payer@example.com';
    private const UNMATCHED = [
        'log_id' => 12,
        'amount' => '325.00',
        'from_name' => 'Pat Smith',
        'reference' => 'CA8kQ2',
    ];

    /** @var FakeOptionStore */
    private $options;
    /** @var FakeEventStore */
    private $store;
    /** @var bool */
    private $tagsActive = true;
    /** @var array<int, array> */
    private $created = [];
    /** @var TestLogger */
    private $logger;
    /** @var int[] Conversations whose note FreeScout refuses. */
    private $failNoteOn = [];

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->failNoteOn = [];
        Thread::reset();
        FakeTag::reset();
        $this->options = new FakeOptionStore();
        $this->store = new FakeEventStore();
        $this->tagsActive = true;
        $this->created = [];
    }

    private function conversation(int $id, int $mailboxId): object
    {
        $conversation = new \stdClass();
        $conversation->id = $id;
        $conversation->mailbox_id = $mailboxId;
        $conversation->customer = new FakeCustomer(self::EMAIL);

        return $conversation;
    }

    /**
     * @param array<string, object[]> $open Open conversations by email.
     * @param array<int, object> $users Active users by id.
     * @param int|null $adminId The first active admin's id; null for none.
     * @return EventRouter&object{lookups: string[]}
     */
    private function router(array $open = [], array $users = [], ?int $adminId = 1)
    {
        $admin = $adminId === null ? null : (object) ['id' => $adminId];
        $tags = new TagService(function () {
            return $this->tagsActive;
        }, FakeTag::class);
        $creator = new class (new EventCatalog(), $this->created) extends TicketCreator {
            /** @var array */
            private $log;

            public function __construct(EventCatalog $catalog, array &$log)
            {
                parent::__construct($catalog);
                $this->log = &$log;
            }

            public function create(array $event, $user, int $mailboxId)
            {
                $this->log[] = [$event, $user, $mailboxId];

                return (object) ['id' => 900, 'mailbox_id' => $mailboxId];
            }
        };

        $failNoteOn = $this->failNoteOn;
        $notes = new class ($failNoteOn) extends NoteWriter {
            /** @var int[] */
            private $failOn;

            public function __construct(array $failOn)
            {
                $this->failOn = $failOn;
            }

            public function add($conversation, $user, string $html): void
            {
                if (in_array((int) $conversation->id, $this->failOn, true)) {
                    throw new \RuntimeException('FreeScout did not create the note.');
                }
                parent::add($conversation, $user, $html);
            }
        };

        return new class (
            $this->options,
            new EventCatalog(),
            $notes,
            $tags,
            $creator,
            $this->store,
            $this->logger,
            $open,
            $users,
            $admin
        ) extends EventRouter {
            /** @var string[] */
            public $lookups = [];
            /** @var array */
            private $open;
            /** @var array */
            private $users;
            /** @var object|null */
            private $admin;

            public function __construct(
                OptionStoreInterface $options,
                EventCatalog $catalog,
                NoteWriter $notes,
                TagService $tags,
                TicketCreator $creator,
                EventStoreInterface $store,
                LoggerInterface $logger,
                array $open,
                array $users,
                ?object $admin
            ) {
                parent::__construct($options, $catalog, $notes, $tags, $creator, $store, $logger);
                $this->open = $open;
                $this->users = $users;
                $this->admin = $admin;
            }

            protected function openConversations(string $email): array
            {
                $this->lookups[] = $email;

                return $this->open[$email] ?? [];
            }

            protected function findActiveUser(int $id)
            {
                return $this->users[$id] ?? null;
            }

            protected function firstActiveAdmin()
            {
                return $this->admin;
            }
        };
    }

    private function event(
        string $type = 'etransfer.unmatched',
        string $email = self::EMAIL,
        array $data = self::UNMATCHED
    ): array {
        return [
            'id' => '1c9e6f3a-2b4d-4c8e-9f00-0123456789ab',
            'type' => $type,
            'email' => $email,
            'occurred_at' => '2026-10-26T14:02:00Z',
            'data' => $data,
        ];
    }

    public function test_every_open_ticket_in_scope_gets_the_note_and_tag(): void
    {
        $this->options->set('spatbridge.mailboxes', [3]);
        $router = $this->router([self::EMAIL => [
            $this->conversation(11, 3),
            $this->conversation(12, 3),
            $this->conversation(13, 5),
        ]]);

        $result = $router->route($this->event());

        $this->assertSame(['outcome' => 'noted', 'conversation_ids' => [11, 12], 'detail' => ''], $result);
        $this->assertCount(2, Thread::$created);
        $note = (new EventCatalog())->noteHtml('etransfer.unmatched', self::UNMATCHED);
        foreach ([11, 12] as $i => $id) {
            [$data, $conversation] = Thread::$created[$i];
            $this->assertSame(['type' => Thread::TYPE_NOTE, 'body' => $note, 'created_by_user_id' => 1], $data);
            $this->assertSame($id, $conversation->id);
        }
        $this->assertSame([['attach', 'payment-unmatched', 11], ['attach', 'payment-unmatched', 12]], FakeTag::$calls);
        $this->assertSame([], $this->created);
    }

    public function test_no_mailbox_selection_means_every_mailbox(): void
    {
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3), $this->conversation(13, 5)]]);

        $this->assertSame([11, 13], $router->route($this->event())['conversation_ids']);
    }

    public function test_notes_off_still_tags(): void
    {
        $this->options->set('spatbridge.events.notes', '');
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]], [], null);

        $result = $router->route($this->event());

        // No note means no note user is needed either.
        $this->assertSame('noted', $result['outcome']);
        $this->assertSame([], Thread::$created);
        $this->assertSame([['attach', 'payment-unmatched', 11]], FakeTag::$calls);
    }

    public function test_tags_unavailable_is_noted_tag_skipped(): void
    {
        $this->tagsActive = false;
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]]);

        $result = $router->route($this->event());

        $this->assertSame('noted_tag_skipped', $result['outcome']);
        $this->assertSame([11], $result['conversation_ids']);
        $this->assertCount(1, Thread::$created);
    }

    public function test_tags_switched_off_are_not_applied_and_not_skipped(): void
    {
        $this->options->set('spatbridge.events.tags', '0');
        $this->tagsActive = false;
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]]);

        $this->assertSame('noted', $router->route($this->event())['outcome']);
        $this->assertSame([], FakeTag::$calls);
    }

    public function test_tag_override_and_empty_override(): void
    {
        $this->options->set('spatbridge.events.etransfer.unmatched.tag', ' unpaid ');
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]]);
        $router->route($this->event());
        $this->assertSame([['attach', 'unpaid', 11]], FakeTag::$calls);

        FakeTag::reset();
        $this->tagsActive = false;
        $this->options->set('spatbridge.events.etransfer.unmatched.tag', '');
        // Nothing to tag, so nothing was skipped.
        $this->assertSame('noted', $router->route($this->event())['outcome']);
        $this->assertSame([], FakeTag::$calls);
    }

    public function test_removal_follows_the_tag_the_other_type_adds(): void
    {
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]]);
        $matched = [
            'log_id' => 12,
            'order_id' => 5107,
            'amount' => '325.00',
            'reference' => 'CA8kQ2',
            'manual' => false,
        ];

        $router->route($this->event('etransfer.matched', self::EMAIL, $matched));
        $this->assertSame([['detach', 'payment-unmatched', 11]], FakeTag::$calls);

        FakeTag::reset();
        $this->options->set('spatbridge.events.etransfer.unmatched.tag', 'unpaid');
        // A saved, empty add-tag on a remove-only type keeps its removal.
        $this->options->set('spatbridge.events.etransfer.matched.tag', '');
        $router->route($this->event('etransfer.matched', self::EMAIL, $matched));
        $this->assertSame([['detach', 'unpaid', 11]], FakeTag::$calls);

        FakeTag::reset();
        $router->route($this->event('waitlist.expired', self::EMAIL, ['season' => 'S2026', 'position' => 'Goalie']));
        $this->assertSame([['attach', 'waitlist-expired', 11], ['detach', 'waitlist-offered', 11]], FakeTag::$calls);
    }

    public function test_closed_ticket_only_with_create_off_is_ignored(): void
    {
        // The router only ever sees open conversations; a closed one is none.
        $router = $this->router([]);

        $result = $router->route($this->event());

        $this->assertSame(['outcome' => 'ignored', 'conversation_ids' => [], 'detail' => ''], $result);
        $this->assertSame([self::EMAIL], $router->lookups);
        $this->assertSame([], $this->created);
        $this->assertSame([], Thread::$created);
    }

    public function test_create_on_makes_a_ticket_and_tags_it(): void
    {
        $this->options->set('spatbridge.events.etransfer.unmatched.create', '1');
        $this->options->set('spatbridge.events.mailbox_id', '3');
        $router = $this->router([], [7 => (object) ['id' => 7]]);
        $this->options->set('spatbridge.events.note_user_id', '7');

        $result = $router->route($this->event());

        $this->assertSame(['outcome' => 'created', 'conversation_ids' => [900], 'detail' => ''], $result);
        $this->assertCount(1, $this->created);
        [$event, $user, $mailboxId] = $this->created[0];
        $this->assertSame('etransfer.unmatched', $event['type']);
        $this->assertSame(7, $user->id);
        $this->assertSame(3, $mailboxId);
        $this->assertSame([['attach', 'payment-unmatched', 900]], FakeTag::$calls);
        // The created ticket's only thread is TicketCreator's; the router adds none.
        $this->assertSame([], Thread::$created);
    }

    public function test_created_ticket_with_tags_unavailable(): void
    {
        $this->tagsActive = false;
        $this->options->set('spatbridge.events.etransfer.unmatched.create', true);
        $this->options->set('spatbridge.events.mailbox_id', 3);

        $this->assertSame('created_tag_skipped', $this->router()->route($this->event())['outcome']);
    }

    public function test_create_needs_a_mailbox(): void
    {
        $this->options->set('spatbridge.events.etransfer.unmatched.create', true);

        $result = $this->router()->route($this->event());

        $this->assertSame(['outcome' => 'error', 'conversation_ids' => [], 'detail' => 'no_mailbox'], $result);
        $this->assertSame([], $this->created);
    }

    public function test_empty_email_with_create_on_creates_a_ticket(): void
    {
        $this->options->set('spatbridge.events.etransfer.unmatched.create', true);
        $this->options->set('spatbridge.events.mailbox_id', 3);
        $router = $this->router();

        $result = $router->route($this->event('etransfer.unmatched', ''));

        $this->assertSame('created', $result['outcome']);
        $this->assertSame([], $router->lookups);
        $this->assertSame('', $this->created[0][0]['email']);
    }

    public function test_empty_email_with_create_off_is_ignored(): void
    {
        $router = $this->router();

        $this->assertSame('ignored', $router->route($this->event('etransfer.unmatched', ''))['outcome']);
        $this->assertSame([], $router->lookups);
        $this->assertSame([], $this->created);
    }

    public function test_create_switch_is_per_type(): void
    {
        $this->options->set('spatbridge.events.registration.incomplete.create', true);
        $this->options->set('spatbridge.events.mailbox_id', 3);

        $this->assertSame('ignored', $this->router()->route($this->event())['outcome']);
    }

    public function test_configured_note_user_falls_back_to_the_first_active_admin(): void
    {
        $this->options->set('spatbridge.events.note_user_id', 7);
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]], [], 2);

        $router->route($this->event());

        $this->assertSame(2, Thread::$created[0][0]['created_by_user_id']);
    }

    public function test_a_ticket_that_fails_does_not_stop_the_others(): void
    {
        $this->failNoteOn = [12];
        $router = $this->router([self::EMAIL => [
            $this->conversation(11, 3),
            $this->conversation(12, 3),
            $this->conversation(13, 3),
        ]]);

        $result = $router->route($this->event());

        $this->assertSame([
            'outcome' => 'noted',
            'conversation_ids' => [11, 13],
            'detail' => '#12: FreeScout did not create the note.',
        ], $result);
        $this->assertCount(2, Thread::$created);
        // The failed ticket isn't tagged either.
        $this->assertSame([['attach', 'payment-unmatched', 11], ['attach', 'payment-unmatched', 13]], FakeTag::$calls);
        $this->assertTrue($this->logger->hasErrorThatContains('on conversation #12 failed'));
    }

    public function test_every_ticket_failing_is_an_error(): void
    {
        $this->failNoteOn = [11, 12];
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3), $this->conversation(12, 3)]]);

        $result = $router->route($this->event());

        $this->assertSame('error', $result['outcome']);
        $this->assertSame([], $result['conversation_ids']);
        $this->assertSame(
            '#11: FreeScout did not create the note.; #12: FreeScout did not create the note.',
            $result['detail']
        );
    }

    public function test_falling_back_from_a_missing_note_user_is_logged(): void
    {
        $this->options->set('spatbridge.events.note_user_id', 7);
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3), $this->conversation(12, 3)]], [], 2);

        $router->route($this->event());

        $this->assertCount(1, $this->logger->records);
        $this->assertTrue($this->logger->hasWarningThatContains('note user #7 is missing or inactive'));
    }

    public function test_a_configured_active_note_user_logs_nothing(): void
    {
        $this->options->set('spatbridge.events.note_user_id', 7);
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]], [7 => (object) ['id' => 7]]);

        $router->route($this->event());

        $this->assertSame(7, Thread::$created[0][0]['created_by_user_id']);
        $this->assertSame([], $this->logger->records);
    }

    public function test_no_note_user_is_an_error_and_changes_nothing(): void
    {
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]], [99 => (object) ['id' => 99]], null);

        $result = $router->route($this->event());

        $this->assertSame(['outcome' => 'error', 'conversation_ids' => [], 'detail' => 'no_note_user'], $result);
        $this->assertSame([], Thread::$created);
        $this->assertSame([], FakeTag::$calls);
    }

    public function test_repeated_registration_outcome_within_a_day_is_noted_once(): void
    {
        $this->store->routed = [['order_id' => 5107, 'outcome' => 'name_rejected']];
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]]);

        $repeat = ['order_id' => '5107', 'outcome' => 'name_rejected'];

        $result = $router->route($this->event('registration.incomplete', self::EMAIL, $repeat));

        $this->assertSame(['outcome' => 'duplicate_outcome', 'conversation_ids' => [], 'detail' => ''], $result);
        $this->assertSame([], Thread::$created);
        $this->assertSame([], FakeTag::$calls);
        [$type, $outcomes, $since] = $this->store->routedQueries[0];
        $this->assertSame('registration.incomplete', $type);
        $this->assertSame(['noted', 'noted_tag_skipped', 'created', 'created_tag_skipped'], $outcomes);
        $this->assertEqualsWithDelta(time() - 86400, $since, 5);
    }

    public function test_a_different_registration_outcome_is_noted(): void
    {
        $this->store->routed = [
            ['order_id' => 5107, 'outcome' => 'name_rejected'],
            ['order_id' => 5108, 'outcome' => 'failed'],
        ];
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]]);

        $other = ['order_id' => 5107, 'outcome' => 'failed'];

        $result = $router->route($this->event('registration.incomplete', self::EMAIL, $other));

        $this->assertSame('noted', $result['outcome']);
    }

    public function test_other_types_are_not_checked_for_repeats(): void
    {
        $router = $this->router([self::EMAIL => [$this->conversation(11, 3)]]);

        $router->route($this->event('registration.reversed', self::EMAIL, ['order_id' => 5107]));

        $this->assertSame([], $this->store->routedQueries);
    }
}

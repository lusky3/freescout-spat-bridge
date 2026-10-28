<?php

namespace Tests\Unit;

use App\Conversation;
use App\Thread;
use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\TicketCreator;
use PHPUnit\Framework\TestCase;

class TicketCreatorTest extends TestCase
{
    private const EVENT_ID = '1c9e6f3a-2b4d-4c8e-9f00-0123456789ab';

    /** @var object */
    private $user;

    protected function setUp(): void
    {
        $this->user = new \stdClass();
        $this->user->id = 4;
    }

    /**
     * @return TicketCreator&object{calls: array, skipDuringCreate: bool|null}
     */
    private function creator(string $mode = 'created')
    {
        return new class (new EventCatalog(), $mode) extends TicketCreator {
            /** @var array<int, array> */
            public $calls = [];
            /** @var bool|null What the skip-send filter said while core was creating. */
            public $skipDuringCreate;
            /** @var string 'created', 'refused' (core returns false) or 'throws' */
            private $mode;

            public function __construct(EventCatalog $catalog, string $mode)
            {
                parent::__construct($catalog);
                $this->mode = $mode;
            }

            protected function findOrCreateCustomer(string $email, array $fields)
            {
                $this->calls[] = ['findOrCreateCustomer', $email, $fields];

                return (object) ['id' => 50, 'email' => $email];
            }

            protected function createCustomerWithoutEmail(array $fields)
            {
                $this->calls[] = ['createCustomerWithoutEmail', $fields];

                return (object) ['id' => 51];
            }

            protected function createConversation(array $data, array $threads, $customer)
            {
                $this->calls[] = ['createConversation', $data, $threads, $customer];
                $conversation = new Conversation();
                $conversation->id = 900;
                $conversation->mailbox = new class {
                    /** @var int */
                    public $countersUpdated = 0;

                    public function updateFoldersCounters(): void
                    {
                        $this->countersUpdated++;
                    }
                };
                // Core fires the send-reply listener inside create(), before
                // the meta exists; the note-only replies collection is empty.
                $this->skipDuringCreate = TicketCreator::skipSend(false, $conversation, []);
                if ($this->mode === 'throws') {
                    throw new \RuntimeException('Mailbox 3 not found.');
                }
                if ($this->mode === 'refused') {
                    return null;
                }

                return $conversation;
            }
        };
    }

    private function event(string $email, array $data, string $type = 'etransfer.unmatched'): array
    {
        return [
            'id' => self::EVENT_ID,
            'type' => $type,
            'email' => $email,
            'occurred_at' => '2026-10-26T14:02:00Z',
            'data' => $data,
        ];
    }

    public function test_creates_a_phone_ticket_with_the_note_as_its_only_thread(): void
    {
        $creator = $this->creator();
        $data = ['order_id' => 5107, 'outcome' => 'name_rejected'];

        $event = $this->event('new-person@example.test', $data, 'registration.incomplete');

        $conversation = $creator->create($event, $this->user, 3);

        $this->assertSame(900, $conversation->id);
        $this->assertSame(['findOrCreateCustomer', 'new-person@example.test', []], $creator->calls[0]);

        [$call, $convData, $threads, $customer] = $creator->calls[1];
        $this->assertSame('createConversation', $call);
        $this->assertSame([
            'type' => Conversation::TYPE_PHONE,
            'subject' => 'Registration not completed: order #5107',
            'mailbox_id' => 3,
            'source_type' => Conversation::SOURCE_TYPE_API,
            'source_via' => Conversation::PERSON_USER,
            'status' => Conversation::STATUS_ACTIVE,
        ], $convData);
        $this->assertSame([[
            'type' => Thread::TYPE_NOTE,
            'body' => (new EventCatalog())->noteHtml('registration.incomplete', $data),
            'created_by_user_id' => 4,
        ]], $threads);
        $this->assertSame(50, $customer->id);

        // Active, sorted as just replied to, marked as the bridge's, saved.
        $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->status);
        $this->assertSame(Conversation::PERSON_USER, $conversation->last_reply_from);
        $this->assertEqualsWithDelta(time(), strtotime((string) $conversation->last_reply_at), 5);
        $this->assertSame(['event_id' => self::EVENT_ID], $conversation->getMeta(TicketCreator::META_KEY));
        $this->assertSame(['updateFolder', 'save'], $conversation->calls);
        $this->assertSame(1, $conversation->mailbox->countersUpdated);
    }

    public function test_a_known_sender_name_is_offered_for_a_new_customer(): void
    {
        $creator = $this->creator();

        $event = $this->event('pat@example.com', ['amount' => '5', 'from_name' => ' Pat  Q Smith ']);

        $creator->create($event, $this->user, 3);

        $this->assertSame(
            ['findOrCreateCustomer', 'pat@example.com', ['first_name' => 'Pat', 'last_name' => 'Q Smith']],
            $creator->calls[0]
        );
    }

    public function test_empty_email_creates_a_customer_named_after_the_sender_without_email(): void
    {
        $creator = $this->creator();

        $creator->create($this->event('', ['amount' => '5', 'from_name' => 'Pat Smith']), $this->user, 3);

        $this->assertSame(
            ['createCustomerWithoutEmail', ['first_name' => 'Pat', 'last_name' => 'Smith']],
            $creator->calls[0]
        );
        $this->assertSame('Unmatched e-Transfer: Pat Smith, $5.00', $creator->calls[1][1]['subject']);
    }

    public function test_empty_email_and_no_sender_name_is_an_unknown_sender(): void
    {
        $creator = $this->creator();

        $creator->create($this->event('', ['amount' => '5']), $this->user, 3);

        $this->assertSame(
            ['createCustomerWithoutEmail', ['first_name' => 'Unknown', 'last_name' => 'sender']],
            $creator->calls[0]
        );
        $this->assertSame('Unmatched e-Transfer: Unknown sender, $5.00', $creator->calls[1][1]['subject']);
    }


    public function test_freescout_refusing_the_conversation_throws(): void
    {
        $creator = $this->creator('refused');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FreeScout did not create the conversation.');
        $creator->create($this->event('pat@example.com', ['amount' => '5']), $this->user, 3);
    }

    private function bridgeTicket(): Conversation
    {
        $ticket = new Conversation();
        $ticket->id = 900;
        $ticket->setMeta(TicketCreator::META_KEY, ['event_id' => self::EVENT_ID]);

        return $ticket;
    }

    private function thread(int $type): object
    {
        return (object) ['type' => $type];
    }

    public function test_skip_send_blocks_mail_while_core_creates_the_ticket(): void
    {
        $creator = $this->creator();

        $creator->create($this->event('pat@example.com', ['amount' => '5']), $this->user, 3);

        $this->assertTrue($creator->skipDuringCreate);
        // The marker is gone once create() returns.
        $this->assertFalse(TicketCreator::skipSend(false, new Conversation(), []));
    }

    public function test_skip_send_marker_is_cleared_when_creation_fails(): void
    {
        $creator = $this->creator('throws');

        try {
            $creator->create($this->event('pat@example.com', ['amount' => '5']), $this->user, 3);
            $this->fail('Expected the creation failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Mailbox 3 not found.', $e->getMessage());
        }

        $this->assertTrue($creator->skipDuringCreate);
        $this->assertFalse(TicketCreator::skipSend(false, new Conversation(), []));
    }

    public function test_skip_send_blocks_mail_on_a_bridge_ticket_without_an_agent_message(): void
    {
        $this->assertTrue(TicketCreator::skipSend(false, $this->bridgeTicket(), []));
        $customerOnly = [$this->thread(Thread::TYPE_CUSTOMER)];
        $this->assertTrue(TicketCreator::skipSend(false, $this->bridgeTicket(), $customerOnly));
        $this->assertTrue(TicketCreator::skipSend(false, $this->bridgeTicket(), null));
    }

    public function test_skip_send_lets_an_agent_reply_on_a_bridge_ticket_go_out(): void
    {
        $replies = new \ArrayIterator([$this->thread(Thread::TYPE_CUSTOMER), $this->thread(Thread::TYPE_MESSAGE)]);

        $this->assertFalse(TicketCreator::skipSend(false, $this->bridgeTicket(), $replies));
    }

    public function test_skip_send_passes_other_tickets_through(): void
    {
        $this->assertFalse(TicketCreator::skipSend(false, new Conversation(), []));
        $this->assertTrue(TicketCreator::skipSend(true, new Conversation(), [$this->thread(Thread::TYPE_MESSAGE)]));
        $this->assertFalse(TicketCreator::skipSend(false, null, []));
    }
}

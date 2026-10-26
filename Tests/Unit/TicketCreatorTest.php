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
     * @return TicketCreator&object{calls: array}
     */
    private function creator(bool $conversationCreated = true)
    {
        return new class (new EventCatalog(), $conversationCreated) extends TicketCreator {
            /** @var array<int, array> */
            public $calls = [];
            /** @var bool */
            private $conversationCreated;

            public function __construct(EventCatalog $catalog, bool $conversationCreated)
            {
                parent::__construct($catalog);
                $this->conversationCreated = $conversationCreated;
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
                if (!$this->conversationCreated) {
                    return null;
                }
                $conversation = new Conversation();
                $conversation->id = 900;

                return $conversation;
            }

            protected function activate($conversation, string $eventId): void
            {
                $this->calls[] = ['activate', $conversation->id, $eventId];
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

        $this->assertSame(['activate', 900, self::EVENT_ID], $creator->calls[2]);
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
        $creator = $this->creator(false);

        $this->expectException(\RuntimeException::class);
        $creator->create($this->event('pat@example.com', ['amount' => '5']), $this->user, 3);
    }

    public function test_skip_send_filter_blocks_customer_mail_on_bridge_tickets_only(): void
    {
        $bridgeTicket = new Conversation();
        $bridgeTicket->setMeta(TicketCreator::META_KEY, ['event_id' => self::EVENT_ID]);
        $otherTicket = new Conversation();

        $this->assertTrue(TicketCreator::skipSend(false, $bridgeTicket));
        $this->assertFalse(TicketCreator::skipSend(false, $otherTicket));
        $this->assertTrue(TicketCreator::skipSend(true, $otherTicket));
        $this->assertFalse(TicketCreator::skipSend(false, null));
    }
}

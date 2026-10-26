<?php

namespace Modules\SpatBridge\Services;

/**
 * Opens a ticket for an event when the customer has no open one and that
 * event type's "create" switch is on.
 *
 * The ticket never mails the customer: it is a phone conversation whose only
 * thread is an internal note, and it carries `spatbridge` meta so the
 * `conversation.skip_send_reply_to_customer` filter (skipSend) stops any mail.
 */
class TicketCreator
{
    public const META_KEY = 'spatbridge';

    /** @var EventCatalog */
    private $catalog;

    public function __construct(EventCatalog $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * @param array{id: string, type: string, email: string, data: array} $event
     * @param object $user The FreeScout user the note is credited to.
     * @return object The new conversation.
     * @throws \RuntimeException When FreeScout refuses the customer or the conversation.
     */
    public function create(array $event, $user, int $mailboxId)
    {
        $type = (string) $event['type'];
        $data = is_array($event['data']) ? $event['data'] : [];
        $email = (string) $event['email'];

        if ($email === '') {
            // FreeScout supports customers without an email (phone tickets).
            $customer = $this->createCustomerWithoutEmail(self::nameFields($this->catalog->sender($data)));
        } else {
            $name = isset($data['from_name']) && is_scalar($data['from_name']) ? trim((string) $data['from_name']) : '';
            $customer = $this->findOrCreateCustomer($email, $name === '' ? [] : self::nameFields($name));
        }
        if (!is_object($customer)) {
            throw new \RuntimeException('FreeScout did not create the customer.');
        }

        $conversation = $this->createConversation(
            [
                'type' => \App\Conversation::TYPE_PHONE,
                'subject' => $this->catalog->subject($type, $data),
                'mailbox_id' => $mailboxId,
                'source_type' => \App\Conversation::SOURCE_TYPE_API,
                'source_via' => \App\Conversation::PERSON_USER,
                'status' => \App\Conversation::STATUS_ACTIVE,
            ],
            [[
                'type' => \App\Thread::TYPE_NOTE,
                'body' => $this->catalog->noteHtml($type, $data),
                'created_by_user_id' => (int) $user->id,
            ]],
            $customer
        );
        if (!is_object($conversation)) {
            throw new \RuntimeException('FreeScout did not create the conversation.');
        }

        $this->activate($conversation, (string) $event['id']);

        return $conversation;
    }

    /**
     * The `conversation.skip_send_reply_to_customer` filter: no customer
     * mail from a ticket the bridge created.
     *
     * @param mixed $skip
     * @param mixed $conversation
     */
    public static function skipSend($skip, $conversation): bool
    {
        if ($skip) {
            return true;
        }

        return is_object($conversation)
            && method_exists($conversation, 'getMeta')
            && !empty($conversation->getMeta(self::META_KEY));
    }

    /**
     * "Pat Q Smith" → first name "Pat", last name "Q Smith".
     *
     * @return array{first_name: string, last_name: string}
     */
    public static function nameFields(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [''];

        return [
            'first_name' => mb_substr($parts[0], 0, 100),
            'last_name' => isset($parts[1]) ? mb_substr(preg_replace('/\s+/', ' ', $parts[1]), 0, 100) : '',
        ];
    }

    // @codeCoverageIgnoreStart
    //
    // Thin seams over FreeScout's models, replaced in the unit tests and
    // exercised by the e2e environment.

    /**
     * @return object|null
     */
    protected function findOrCreateCustomer(string $email, array $fields)
    {
        return \App\Customer::create($email, $fields);
    }

    /**
     * @return object|null
     */
    protected function createCustomerWithoutEmail(array $fields)
    {
        return \App\Customer::createWithoutEmail($fields);
    }

    /**
     * @return object|null
     */
    protected function createConversation(array $data, array $threads, $customer)
    {
        if (!\App\Mailbox::find($data['mailbox_id'])) {
            throw new \RuntimeException('Mailbox ' . $data['mailbox_id'] . ' not found.');
        }

        $result = \App\Conversation::create($data, $threads, $customer);

        return is_array($result) ? $result['conversation'] : null;
    }

    /**
     * Conversation::create() always makes a phone conversation Pending;
     * the bridge's tickets need an agent, so they start Active.
     *
     * @param object $conversation
     */
    protected function activate($conversation, string $eventId): void
    {
        $conversation->status = \App\Conversation::STATUS_ACTIVE;
        $conversation->setMeta(self::META_KEY, ['event_id' => $eventId]);
        $conversation->updateFolder();
        $conversation->save();
        $conversation->mailbox->updateFoldersCounters();
    }
    // @codeCoverageIgnoreEnd
}

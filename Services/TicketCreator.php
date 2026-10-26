<?php

namespace Modules\SpatBridge\Services;

/**
 * Opens a ticket for an event when the customer has no open one and that
 * event type's "create" switch is on.
 *
 * Opening the ticket doesn't mail the customer. It is a phone conversation
 * whose only thread is an internal note, and the
 * `conversation.skip_send_reply_to_customer` filter (skipSend) blocks
 * customer mail for it until an agent writes a real reply. FreeScout runs
 * that filter inside Conversation::create(), before the ticket's
 * `spatbridge` meta can be saved, so an in-flight marker covers creation and
 * the meta covers everything after.
 */
class TicketCreator
{
    public const META_KEY = 'spatbridge';

    /** @var string|null The event id while create() is making its conversation. */
    private static $creating;

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

        self::$creating = (string) $event['id'];
        try {
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
        } finally {
            self::$creating = null;
        }
        if (!is_object($conversation)) {
            throw new \RuntimeException('FreeScout did not create the conversation.');
        }

        $this->activate($conversation, (string) $event['id']);

        return $conversation;
    }

    /**
     * The `conversation.skip_send_reply_to_customer` filter, called by core
     * as ($skip, $conversation, $replies). Blocks customer mail for a bridge
     * ticket (being created now, or carrying `spatbridge` meta) while the
     * replies about to be sent hold no agent message. Once an agent writes a
     * real reply, $skip passes through unchanged and the reply goes out.
     *
     * @param mixed $skip
     * @param mixed $conversation
     * @param iterable|mixed $replies Threads core is about to send.
     */
    public static function skipSend($skip, $conversation, $replies = []): bool
    {
        if ($skip) {
            return true;
        }

        $bridgeTicket = self::$creating !== null || (
            is_object($conversation)
            && method_exists($conversation, 'getMeta')
            && !empty($conversation->getMeta(self::META_KEY))
        );

        return $bridgeTicket && !self::hasAgentMessage($replies);
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

    /**
     * Conversation::create() always makes a phone conversation Pending;
     * the bridge's tickets need an agent, so they start Active. Like core's
     * own phone-plus-note path, the note counts as the last reply, so the
     * ticket sorts by now in the agent queue rather than at the bottom.
     *
     * @param object $conversation
     */
    protected function activate($conversation, string $eventId): void
    {
        $conversation->status = \App\Conversation::STATUS_ACTIVE;
        $conversation->last_reply_at = date('Y-m-d H:i:s');
        $conversation->last_reply_from = \App\Conversation::PERSON_USER;
        $conversation->setMeta(self::META_KEY, ['event_id' => $eventId]);
        $conversation->updateFolder();
        $conversation->save();
        $conversation->mailbox->updateFoldersCounters();
    }

    /**
     * @param iterable|mixed $replies
     */
    private static function hasAgentMessage($replies): bool
    {
        if (!is_iterable($replies)) {
            return false;
        }
        foreach ($replies as $reply) {
            if (is_object($reply) && (int) ($reply->type ?? 0) === \App\Thread::TYPE_MESSAGE) {
                return true;
            }
        }

        return false;
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
    // @codeCoverageIgnoreEnd
}

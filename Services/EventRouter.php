<?php

namespace Modules\SpatBridge\Services;

use Modules\SpatBridge\Services\Support\OptionBool;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides what an event from WordPress does in FreeScout: an internal note
 * and tag on each of the customer's open tickets, a new ticket when there
 * are none and the type's create switch is on, or nothing.
 */
class EventRouter
{
    public const NOTED = 'noted';
    public const NOTED_TAG_SKIPPED = 'noted_tag_skipped';
    public const TAGGED = 'tagged';
    public const TAG_SKIPPED = 'tag_skipped';
    public const NOTES_TAGS_OFF = 'notes_tags_off';
    public const IGNORED_UNKNOWN_TYPE = 'ignored_unknown_type';
    public const CREATED = 'created';
    public const CREATED_TAG_SKIPPED = 'created_tag_skipped';
    public const IGNORED = 'ignored';
    public const DUPLICATE_OUTCOME = 'duplicate_outcome';
    public const ERROR = 'error';

    /** Outcomes that put something on a ticket. */
    private const ROUTED = [self::NOTED, self::NOTED_TAG_SKIPPED, self::CREATED, self::CREATED_TAG_SKIPPED];

    /**
     * WordPress fires registration.incomplete on every re-run of an order,
     * so the same order and outcome is noted once a day at most.
     */
    private const REPEAT_TYPE = 'registration.incomplete';
    private const REPEAT_WINDOW = 86400;

    /** @var OptionStoreInterface */
    private $options;
    /** @var EventCatalog */
    private $catalog;
    /** @var NoteWriter */
    private $notes;
    /** @var TagService */
    private $tags;
    /** @var TicketCreator */
    private $creator;
    /** @var EventStoreInterface */
    private $store;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        OptionStoreInterface $options,
        EventCatalog $catalog,
        NoteWriter $notes,
        TagService $tags,
        TicketCreator $creator,
        EventStoreInterface $store,
        LoggerInterface $logger
    ) {
        $this->options = $options;
        $this->catalog = $catalog;
        $this->notes = $notes;
        $this->tags = $tags;
        $this->creator = $creator;
        $this->store = $store;
        $this->logger = $logger;
    }

    /**
     * @param array{id: string, type: string, email: string, occurred_at: string, data: array} $event
     * @return array{outcome: string, conversation_ids: int[], detail: string}
     */
    public function route(array $event): array
    {
        if ($this->isRepeat($event)) {
            return self::result(self::DUPLICATE_OUTCOME);
        }

        $conversations = [];
        if ($event['email'] !== '') {
            foreach ($this->openConversations($event['email']) as $conversation) {
                if (MailboxScope::allows($this->options, (int) ($conversation->mailbox_id ?? 0))) {
                    $conversations[] = $conversation;
                }
            }
        }

        if ($conversations !== []) {
            return $this->noteAll($event, $conversations);
        }

        if (
            !$this->catalog->canCreate($event['type'])
            || !$this->bool('spatbridge.events.' . $event['type'] . '.create', false)
        ) {
            return self::result(self::IGNORED);
        }

        return $this->createTicket($event);
    }

    /**
     * Each ticket is handled on its own: one that fails is logged and named
     * in the detail, and the rest still get their note and tag.
     *
     * @param object[] $conversations
     */
    private function noteAll(array $event, array $conversations): array
    {
        $notesOn = $this->bool('spatbridge.events.notes', true);
        if (!$notesOn && !$this->bool('spatbridge.events.tags', true)) {
            $ids = array_map(function ($conversation) {
                return (int) $conversation->id;
            }, $conversations);

            return self::result(self::NOTES_TAGS_OFF, $ids);
        }
        $user = null;
        if ($notesOn) {
            $user = $this->noteUser();
            if ($user === null) {
                return self::result(self::ERROR, [], 'no_note_user');
            }
        }

        $html = $this->catalog->noteHtml($event['type'], $event['data']);
        $tagged = true;
        $ids = [];
        $failures = [];
        foreach ($conversations as $conversation) {
            $id = (int) $conversation->id;
            try {
                if ($notesOn) {
                    $this->notes->add($conversation, $user, $html);
                }
                $tagged = $this->applyTags($conversation, $event['type']) && $tagged;
                $ids[] = $id;
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[SpatBridge] event ' . $event['id'] . ' on conversation #' . $id . ' failed: ' . $e->getMessage()
                );
                $failures[] = '#' . $id . ': ' . $e->getMessage();
            }
        }

        $detail = implode('; ', $failures);
        if ($ids === []) {
            return self::result(self::ERROR, [], $detail);
        }

        if (!$notesOn) {
            return self::result($tagged ? self::TAGGED : self::TAG_SKIPPED, $ids, $detail);
        }

        return self::result($tagged ? self::NOTED : self::NOTED_TAG_SKIPPED, $ids, $detail);
    }

    private function createTicket(array $event): array
    {
        $mailboxId = (int) $this->options->get('spatbridge.events.mailbox_id', 0);
        if ($mailboxId <= 0) {
            return self::result(self::ERROR, [], 'no_mailbox');
        }
        $user = $this->noteUser();
        if ($user === null) {
            return self::result(self::ERROR, [], 'no_note_user');
        }

        $conversation = $this->creator->create($event, $user, $mailboxId);
        $tagged = $this->applyTags($conversation, $event['type']);

        return self::result($tagged ? self::CREATED : self::CREATED_TAG_SKIPPED, [(int) $conversation->id]);
    }

    /**
     * @param object $conversation
     * @return bool False when a tag should have changed but couldn't.
     */
    private function applyTags($conversation, string $type): bool
    {
        if (!$this->bool('spatbridge.events.tags', true)) {
            return true;
        }

        $ok = true;
        $add = $this->addTag($type);
        if ($add !== '') {
            $ok = $this->tags->add($conversation, $add) && $ok;
        }
        $remove = $this->removeTag($type);
        if ($remove !== '') {
            $ok = $this->tags->remove($conversation, $remove) && $ok;
        }

        return $ok;
    }

    /**
     * The tag a type adds: the admin's `spatbridge.events.{type}.tag` when
     * saved (empty = none), otherwise the catalog default.
     */
    private function addTag(string $type): string
    {
        $override = $this->options->get('spatbridge.events.' . $type . '.tag');
        if ($override === null) {
            $default = $this->catalog->defaultTag($type);

            return $default['add'] ?? '';
        }

        return is_scalar($override) ? trim((string) $override) : '';
    }

    /**
     * The tag a type removes is the one another type adds, so it follows
     * that type's setting.
     */
    private function removeTag(string $type): string
    {
        $source = $this->catalog->removesTagOf($type);
        if ($source !== null) {
            return $this->addTag($source);
        }
        $default = $this->catalog->defaultTag($type);

        return $default['remove'] ?? '';
    }

    private function isRepeat(array $event): bool
    {
        if ($event['type'] !== self::REPEAT_TYPE) {
            return false;
        }
        $orderId = $this->scalar($event['data'], 'order_id');
        if ($orderId === '') {
            return false;
        }
        $outcome = $this->scalar($event['data'], 'outcome');

        $earlier = $this->store->routedSince(self::REPEAT_TYPE, self::ROUTED, time() - self::REPEAT_WINDOW);
        foreach ($earlier as $data) {
            if (
                is_array($data)
                && $this->scalar($data, 'order_id') === $orderId
                && $this->scalar($data, 'outcome') === $outcome
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user notes and tickets are credited to: `spatbridge.events.note_user_id`
     * when that user is active, otherwise the first active admin.
     *
     * @return object|null
     */
    private function noteUser()
    {
        $id = (int) $this->options->get('spatbridge.events.note_user_id', 0);
        if ($id > 0) {
            $user = $this->findActiveUser($id);
            if ($user !== null) {
                return $user;
            }
            $this->logger->warning(
                '[SpatBridge] events note user #' . $id . ' is missing or inactive; using the first active admin.'
            );
        }

        return $this->firstActiveAdmin();
    }

    private function bool(string $key, bool $default): bool
    {
        return OptionBool::from($this->options->get($key), $default);
    }

    private function scalar(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param int[] $conversationIds
     * @return array{outcome: string, conversation_ids: int[], detail: string}
     */
    private static function result(string $outcome, array $conversationIds = [], string $detail = ''): array
    {
        return ['outcome' => $outcome, 'conversation_ids' => $conversationIds, 'detail' => $detail];
    }

    // @codeCoverageIgnoreStart
    //
    // Thin seams over FreeScout's models, replaced in the unit tests and
    // exercised by the e2e environment.

    /**
     * The customer's published Active or Pending conversations, newest first.
     *
     * @return object[]
     */
    protected function openConversations(string $email): array
    {
        $customer = \App\Customer::getByEmail(\App\Email::sanitizeEmail($email));
        if (!$customer) {
            return [];
        }

        return \App\Conversation::where('customer_id', $customer->id)
            ->where('state', \App\Conversation::STATE_PUBLISHED)
            ->whereIn('status', [\App\Conversation::STATUS_ACTIVE, \App\Conversation::STATUS_PENDING])
            ->orderBy('last_reply_at', 'desc')
            ->get()
            ->all();
    }

    /**
     * @return object|null
     */
    protected function findActiveUser(int $id)
    {
        return \App\User::where('id', $id)
            ->where('status', \App\User::STATUS_ACTIVE)
            ->where('type', \App\User::TYPE_USER)
            ->first();
    }

    /**
     * @return object|null
     */
    protected function firstActiveAdmin()
    {
        return \App\User::where('role', \App\User::ROLE_ADMIN)
            ->where('status', \App\User::STATUS_ACTIVE)
            ->where('type', \App\User::TYPE_USER)
            ->orderBy('id')
            ->first();
    }
    // @codeCoverageIgnoreEnd
}

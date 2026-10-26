<?php

namespace Modules\SpatBridge\Services;

use Modules\SpatBridge\Services\Support\OptionStoreInterface;

/**
 * Which mailboxes the module works in (`spatbridge.mailboxes`). An empty or
 * missing list means every mailbox. The mailbox new event tickets go to
 * (`spatbridge.events.mailbox_id`) is always in scope, so the bridge can see
 * and act on the tickets it creates. Shared by the sidebar, the action
 * endpoints and the event router so all follow the same rule.
 */
final class MailboxScope
{
    public static function allows(OptionStoreInterface $options, int $mailboxId): bool
    {
        $selected = $options->get('spatbridge.mailboxes', []);
        if (!is_array($selected) || $selected === []) {
            return true;
        }

        $eventsMailboxId = (int) $options->get('spatbridge.events.mailbox_id', 0);
        if ($eventsMailboxId > 0 && $mailboxId === $eventsMailboxId) {
            return true;
        }

        return in_array($mailboxId, array_map('intval', $selected), true);
    }
}

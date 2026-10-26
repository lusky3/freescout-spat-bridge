<?php

namespace Modules\SpatBridge\Services;

use Modules\SpatBridge\Services\Support\OptionStoreInterface;

/**
 * Which mailboxes the module works in (`spatbridge.mailboxes`). An empty or
 * missing list means every mailbox. Shared by the sidebar and the action
 * endpoints so both follow the same rule.
 */
final class MailboxScope
{
    public static function allows(OptionStoreInterface $options, int $mailboxId): bool
    {
        $selected = $options->get('spatbridge.mailboxes', []);
        if (!is_array($selected) || $selected === []) {
            return true;
        }

        return in_array($mailboxId, array_map('intval', $selected), true);
    }
}

<?php

namespace Modules\SpatBridge\Services;

/**
 * Whether a FreeScout mailbox exists.
 */
class MailboxLookup
{
    // @codeCoverageIgnoreStart
    //
    // Thin wrapper over FreeScout's Mailbox model; tests pass their own.
    public function exists(int $id): bool
    {
        return $id > 0 && \App\Mailbox::find($id) !== null;
    }
    // @codeCoverageIgnoreEnd
}

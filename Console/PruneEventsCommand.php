<?php

namespace Modules\SpatBridge\Console;

use Illuminate\Console\Command;
use Modules\SpatBridge\Services\EventStoreInterface;

/**
 * Deletes logged WordPress events older than 90 days. Scheduled daily
 * through FreeScout's `schedule` filter.
 */
class PruneEventsCommand extends Command
{
    public const KEEP_DAYS = 90;

    /** @var string */
    protected $signature = 'spatbridge:prune-events';

    /** @var string */
    protected $description = 'Delete ARL League events older than 90 days from the event log';

    // @codeCoverageIgnoreStart
    //
    // Needs Laravel's console kernel; run by FreeScout's scheduler.
    public function handle(EventStoreInterface $store)
    {
        $deleted = $store->prune(time() - self::KEEP_DAYS * 86400);
        $this->info('Deleted ' . $deleted . ' event(s).');
    }
    // @codeCoverageIgnoreEnd
}

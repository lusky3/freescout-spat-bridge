<?php

namespace Modules\SpatBridge\Services;

/**
 * The log of events received from WordPress (table `spatbridge_events`).
 */
interface EventStoreInterface
{
    /**
     * Stores a just-received event before it is routed.
     *
     * @param array{id: string, type: string, email: string, occurred_at: string, data: array} $event
     * @param string $payload The raw request body.
     * @return int|null The row id, or null when this event id is already stored.
     */
    public function insert(array $event, string $payload): ?int;

    /**
     * Records how the event was routed.
     *
     * @param int[] $conversationIds
     */
    public function finish(int $rowId, string $outcome, array $conversationIds, string $detail): void;

    /**
     * The `data` of events of $type stored at or after $since (Unix time)
     * whose outcome is one of $outcomes.
     *
     * @param string[] $outcomes
     * @return array<int, array>
     */
    public function routedSince(string $type, array $outcomes, int $since): array;

    /**
     * Deletes events stored before $before (Unix time).
     *
     * @return int The number of rows deleted.
     */
    public function prune(int $before): int;
}

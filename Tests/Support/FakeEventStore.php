<?php

namespace Tests\Support;

use Modules\SpatBridge\Services\EventStoreInterface;

/**
 * In-memory event log. insert() refuses an event_id it already holds, like
 * the table's unique index does.
 */
class FakeEventStore implements EventStoreInterface
{
    /** @var array<int, array{event: array, payload: string}> Keyed by row id. */
    public $rows = [];

    /** @var array<int, array{0: int, 1: string, 2: int[], 3: string}> */
    public $finished = [];

    /** @var array<int, array{0: string, 1: string[], 2: int}> */
    public $routedQueries = [];

    /** @var array<int, array> What routedSince() returns. */
    public $routed = [];

    /** @var \Throwable|null */
    public $insertFails;

    public function insert(array $event, string $payload): ?int
    {
        if ($this->insertFails !== null) {
            throw $this->insertFails;
        }
        foreach ($this->rows as $row) {
            if ($row['event']['id'] === $event['id']) {
                return null;
            }
        }
        $id = count($this->rows) + 1;
        $this->rows[$id] = ['event' => $event, 'payload' => $payload];

        return $id;
    }

    public function finish(int $rowId, string $outcome, array $conversationIds, string $detail): void
    {
        $this->finished[] = [$rowId, $outcome, $conversationIds, $detail];
    }

    public function routedSince(string $type, array $outcomes, int $since): array
    {
        $this->routedQueries[] = [$type, $outcomes, $since];

        return $this->routed;
    }

    public function prune(int $before): int
    {
        return 0;
    }
}

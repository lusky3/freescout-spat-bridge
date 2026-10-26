<?php

namespace Modules\SpatBridge\Services;

/**
 * EventStoreInterface on FreeScout's database (query builder, no model).
 */
class DatabaseEventStore implements EventStoreInterface
{
    public const TABLE = 'spatbridge_events';
    public const RECEIVED = 'received';

    // @codeCoverageIgnoreStart
    //
    // Thin wrapper over Laravel's query builder; exercised by the e2e
    // environment against FreeScout's real database.

    public function insert(array $event, string $payload): ?int
    {
        $now = $this->now();

        try {
            return (int) \DB::table(self::TABLE)->insertGetId([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'email' => $event['email'],
                'payload' => $payload,
                'outcome' => self::RECEIVED,
                'conversation_ids' => '',
                'detail' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // The unique index on event_id is what makes a concurrent retry
            // a duplicate; any other failure is a real error.
            if (\DB::table(self::TABLE)->where('event_id', $event['id'])->exists()) {
                return null;
            }

            throw $e;
        }
    }

    public function finish(int $rowId, string $outcome, array $conversationIds, string $detail): void
    {
        \DB::table(self::TABLE)->where('id', $rowId)->update([
            'outcome' => $outcome,
            'conversation_ids' => self::idList($conversationIds),
            'detail' => $detail,
            'updated_at' => $this->now(),
        ]);
    }

    public function routedSince(string $type, array $outcomes, int $since): array
    {
        $payloads = \DB::table(self::TABLE)
            ->where('type', $type)
            ->whereIn('outcome', $outcomes)
            ->where('created_at', '>=', date('Y-m-d H:i:s', $since))
            ->orderBy('id', 'desc')
            ->limit(500)
            ->pluck('payload');

        $data = [];
        foreach ($payloads as $payload) {
            $decoded = json_decode((string) $payload, true);
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                $data[] = $decoded['data'];
            }
        }

        return $data;
    }

    public function latest(int $limit): array
    {
        $rows = \DB::table(self::TABLE)
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get(['type', 'email', 'outcome', 'conversation_ids', 'detail', 'created_at']);

        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'type' => (string) $row->type,
                'email' => (string) $row->email,
                'outcome' => (string) $row->outcome,
                'conversation_ids' => (string) $row->conversation_ids,
                'detail' => (string) $row->detail,
                'created_at' => (string) $row->created_at,
            ];
        }

        return $events;
    }

    public function prune(int $before): int
    {
        return (int) \DB::table(self::TABLE)->where('created_at', '<', date('Y-m-d H:i:s', $before))->delete();
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
    // @codeCoverageIgnoreEnd

    /**
     * Comma list that fits the 255-character column, cut at a comma.
     *
     * @param int[] $ids
     */
    public static function idList(array $ids): string
    {
        $list = implode(',', array_map('intval', $ids));
        if (strlen($list) <= 255) {
            return $list;
        }

        $cut = substr($list, 0, 256);

        return substr($cut, 0, (int) strrpos($cut, ','));
    }
}

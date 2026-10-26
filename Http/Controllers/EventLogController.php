<?php

namespace Modules\SpatBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\EventStoreInterface;

/**
 * Admin-only page listing the latest events received from WordPress.
 */
class EventLogController extends Controller
{
    public const LIMIT = 100;

    /** @var EventStoreInterface */
    private $store;
    /** @var EventCatalog */
    private $catalog;

    public function __construct(EventStoreInterface $store, ?EventCatalog $catalog = null)
    {
        $this->store = $store;
        $this->catalog = $catalog ?? new EventCatalog();
    }

    public function index()
    {
        return view('spatbridge::events.log', ['events' => $this->rows()]);
    }

    /**
     * @return array<int, array{
     *     time: string, type: string, email: string, outcome: string, conversationIds: int[], detail: string
     * }>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->store->latest(self::LIMIT) as $event) {
            $rows[] = [
                'time' => $event['created_at'],
                'type' => $this->catalog->label($event['type']),
                'email' => $event['email'],
                'outcome' => $this->catalog->outcomeLabel($event['outcome']),
                'conversationIds' => self::ids($event['conversation_ids']),
                'detail' => $event['detail'],
            ];
        }

        return $rows;
    }

    /**
     * @return int[]
     */
    private static function ids(string $list): array
    {
        $ids = [];
        foreach (explode(',', $list) as $part) {
            if (ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }
}

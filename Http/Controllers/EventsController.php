<?php

namespace Modules\SpatBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\EventRouter;
use Modules\SpatBridge\Services\EventStoreInterface;
use Modules\SpatBridge\Services\Signer;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /spatbridge/events: signed events from WordPress (public route).
 *
 * WordPress treats any 2xx as delivered, gives up on a 4xx (except 429) and
 * retries a 5xx. So once an event is stored this always answers 200, even
 * when routing fails: a retry would only add a second note.
 */
class EventsController extends Controller
{
    // The payload column is TEXT (64 KB).
    private const MAX_BODY = 65535;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const ISO_8601 = '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

    /** @var OptionStoreInterface */
    private $options;
    /** @var EventStoreInterface */
    private $store;
    /** @var EventRouter */
    private $router;
    /** @var EventCatalog */
    private $catalog;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        OptionStoreInterface $options,
        EventStoreInterface $store,
        EventRouter $router,
        EventCatalog $catalog,
        LoggerInterface $logger
    ) {
        $this->options = $options;
        $this->store = $store;
        $this->router = $router;
        $this->catalog = $catalog;
        $this->logger = $logger;
    }

    public function receive(Request $request): JsonResponse
    {
        $secret = (string) $this->options->get('spatbridge.shared_secret', '');
        if ($secret === '') {
            return $this->error('The ARL League event receiver is not configured.', 503);
        }

        $body = (string) $request->getContent();
        $signed = Signer::verify(
            (string) $request->header(Signer::HEADER_TIMESTAMP, ''),
            (string) $request->header(Signer::HEADER_SIGNATURE, ''),
            $body,
            $secret,
            time()
        );
        if (!$signed) {
            return $this->error('Bad or stale signature.', 403);
        }

        $event = $this->parse($body);
        if ($event === null) {
            return $this->error('Bad event.', 400);
        }

        try {
            $rowId = $this->store->insert($event, $body);
        } catch (\Throwable $e) {
            // Nothing was stored, so WordPress may safely retry.
            $this->logger->error('[SpatBridge] storing event ' . $event['id'] . ' failed: ' . $e->getMessage());

            return $this->error('The event could not be stored.', 500);
        }
        if ($rowId === null) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        try {
            $result = $this->router->route($event);
        } catch (\Throwable $e) {
            $this->logger->error('[SpatBridge] routing event ' . $event['id'] . ' failed: ' . $e->getMessage());
            $result = ['outcome' => EventRouter::ERROR, 'conversation_ids' => [], 'detail' => $e->getMessage()];
        }

        try {
            $this->store->finish(
                $rowId,
                $result['outcome'],
                $result['conversation_ids'],
                mb_substr((string) $result['detail'], 0, 255)
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                '[SpatBridge] recording the outcome of event ' . $event['id'] . ' failed: ' . $e->getMessage()
            );
        }

        return new JsonResponse(['status' => 'ok', 'outcome' => $result['outcome']]);
    }

    /**
     * @return array{id: string, type: string, email: string, occurred_at: string, data: array}|null
     */
    private function parse(string $body): ?array
    {
        if (strlen($body) > self::MAX_BODY) {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        $id = $decoded['id'] ?? null;
        $type = $decoded['type'] ?? null;
        $email = $decoded['email'] ?? null;
        $occurredAt = $decoded['occurred_at'] ?? null;
        $data = $decoded['data'] ?? null;

        if (!is_string($id) || !preg_match(self::UUID, $id)) {
            return null;
        }
        if (!is_string($type) || !$this->catalog->has($type)) {
            return null;
        }
        if (!is_string($email)) {
            return null;
        }
        $email = strtolower(trim($email));
        if ($email !== '' && (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return null;
        }
        if (!is_string($occurredAt) || !$this->isIsoDate($occurredAt)) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        return [
            'id' => strtolower($id),
            'type' => $type,
            'email' => $email,
            'occurred_at' => $occurredAt,
            'data' => $data,
        ];
    }

    private function isIsoDate(string $value): bool
    {
        return preg_match(self::ISO_8601, $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $status);
    }
}

<?php

namespace Modules\SpatBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\FeatureRegistry;
use Modules\SpatBridge\Features\AddsNoteFollowUp;
use Modules\SpatBridge\Features\GuardsActionParams;
use Modules\SpatBridge\Services\ActionPermissions;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\ConnectionMessage;
use Modules\SpatBridge\Services\MailboxScope;
use Modules\SpatBridge\Services\NoteWriter;
use Modules\SpatBridge\Services\SpatClient;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * The action pop-up: preview (a WordPress dry run), run, and the read-only
 * queries a pop-up may need. Every request is checked from scratch, and Run
 * only runs exactly what this agent previewed (the preview binding).
 */
class ActionController extends Controller
{
    public const NO_PERMISSION = "You don't have permission to run this action.";
    public const PREVIEW_EXPIRED = 'This preview has expired. Close the window and open the action again.';

    private const PREVIEW_VIEW = 'spatbridge::action.preview';
    private const ERROR_VIEW = 'spatbridge::action.error';
    private const KEY_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';
    // Laravel 5.5 cache TTLs are minutes; WordPress keeps replays for 24 h.
    private const NOTED_TTL = 1440;
    // Minutes an agent has between seeing a preview and confirming it.
    private const PREVIEW_TTL = 60;

    /** @var SpatClient */
    private $client;
    /** @var FeatureRegistry */
    private $registry;
    /** @var OptionStoreInterface */
    private $options;
    /** @var NoteWriter */
    private $notes;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        SpatClient $client,
        FeatureRegistry $registry,
        OptionStoreInterface $options,
        NoteWriter $notes,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->registry = $registry;
        $this->options = $options;
        $this->notes = $notes;
        $this->logger = $logger;
    }

    public function preview(Request $request)
    {
        $context = $this->context($request, $this->stringInput($request, 'action'), true);
        if (isset($context['error'])) {
            // Refused before any WordPress call. 200 so the pop-up shows the
            // message; FreeScout replaces any non-2xx body with a generic error.
            return $this->renderView(self::ERROR_VIEW, ['message' => $context['error'][1]], 200);
        }

        /** @var Feature $feature */
        $feature = $context['feature'];
        $base = [
            'feature' => $feature->key(),
            'action' => $context['name'],
            'params' => $context['params'],
            'paramsJson' => $this->paramsJson($context['params']),
            'conversationId' => (int) $context['conversation']->id,
        ];

        $override = $feature->previewOverride($context['name'], $context['params']);
        if (is_array($override) && isset($override['view']) && is_string($override['view'])) {
            $data = isset($override['data']) && is_array($override['data']) ? $override['data'] : [];

            return $this->renderView($override['view'], array_merge($base, $data), 200);
        }

        $key = $this->newIdempotencyKey();
        $result = $this->runAction($context, $key, true);
        if (!$result->ok()) {
            return $this->renderView(self::ERROR_VIEW, ['message' => ConnectionMessage::actionMessage($result)], 200);
        }

        $data = $result->data() ?: [];
        \Cache::put(
            $this->previewCacheKey($key),
            json_encode(['hash' => $this->previewBinding($context), 'codes' => $this->allowedCodes($data)]),
            self::PREVIEW_TTL
        );

        return $this->renderView(self::PREVIEW_VIEW, array_merge($base, [
            'idempotencyKey' => $key,
            'summary' => $this->text($data, 'summary'),
            'changes' => $this->changes($data),
            'confirms' => $this->confirms($data),
        ]), 200);
    }

    public function run(Request $request): JsonResponse
    {
        $context = $this->context($request, $this->stringInput($request, 'action'), true);
        if (isset($context['error'])) {
            return $this->error($context['error'][1], $context['error'][0]);
        }

        $key = $request->input('idempotency_key');
        if (!is_string($key) || !preg_match(self::KEY_PATTERN, $key)) {
            return $this->error(__('This pop-up is out of date. Close it and open the action again.'), 400);
        }

        // Hashed before the confirm codes are mapped, so ticking a box can't
        // change what was previewed.
        $stored = json_decode((string) \Cache::get($this->previewCacheKey($key)), true);
        if (!is_array($stored) || ($stored['hash'] ?? null) !== $this->previewBinding($context)) {
            return $this->error(__(self::PREVIEW_EXPIRED), 409);
        }

        // Only conditions the preview showed may be confirmed.
        $codes = array_values(array_filter((array) $request->input('confirm', []), 'is_string'));
        $allowed = is_array($stored['codes'] ?? null) ? $stored['codes'] : [];
        if (array_diff($codes, $allowed) !== []) {
            return $this->error(__(self::PREVIEW_EXPIRED), 409);
        }
        /** @var Feature $feature */
        $feature = $context['feature'];
        $context['params'] = $feature->mapConfirmCodes($context['name'], $codes, $context['params']);

        $result = $this->runAction($context, $key, false);
        if (!$result->ok()) {
            return $this->error(ConnectionMessage::actionMessage($result), 200);
        }

        $data = $result->data() ?: [];
        if (!empty($data['dry_run'])) {
            return $this->error(__("WordPress didn't run the action. Try again."), 200);
        }
        // SPAT always sets success; anything else (a proxy's page, an older
        // SPAT) is not proof the action ran, so no note says it did.
        if (($data['success'] ?? null) !== true) {
            return $this->error(
                __("WordPress didn't report the action as done. Check WordPress before trying again."),
                200
            );
        }
        $summary = $this->text($data, 'summary');
        $message = $summary === '' ? __('Done.') : $summary;
        $followUp = $feature instanceof AddsNoteFollowUp
            ? $feature->noteFollowUp($context['name'], $context['params'])
            : null;

        $replayed = !empty($data['replayed']);
        if (!$this->addNoteOnce($context, $key, $message, $this->changes($data), $replayed, $followUp)) {
            $message .= ' ' . __("The internal note couldn't be added.");
        }
        $this->forgetCustomerCache($context['conversation']);

        return new JsonResponse(['status' => 'success', 'msg' => $message]);
    }

    public function query(Request $request): JsonResponse
    {
        $context = $this->context($request, $this->stringInput($request, 'query'), false);
        if (isset($context['error'])) {
            return $this->error($context['error'][1], $context['error'][0]);
        }

        $result = $this->client->query($context['feature']->key(), $context['name'], $context['params']);
        if (!$result->ok()) {
            return $this->error(ConnectionMessage::actionMessage($result), 200);
        }

        $data = $result->data() ?: [];
        $results = isset($data['results']) && is_array($data['results']) ? $data['results'] : [];
        // WordPress wraps whatever the query returns, so a single record
        // (e.g. one order) arrives as an object, not a list.
        if ($results !== [] && array_keys($results) !== range(0, count($results) - 1)) {
            $results = [$results];
        }

        return new JsonResponse(['status' => 'success', 'results' => array_values($results)]);
    }

    /**
     * The checks every endpoint runs, in order: the agent can see the
     * conversation, may run this feature's actions, and the action (or
     * query) belongs to the feature.
     *
     * @return array{error: array{0: int, 1: string}}|array{conversation: object, user: object,
     *         feature: Feature, name: string, params: array}
     */
    private function context(Request $request, string $name, bool $isAction): array
    {
        $user = $request->user();
        if (!is_object($user)) {
            return ['error' => [403, __(self::NO_PERMISSION)]];
        }
        $conversation = $this->findConversation((int) $request->input('conversation_id', 0));
        if (!is_object($conversation)) {
            return ['error' => [404, __('Conversation not found.')]];
        }
        if (!$user->can('view', $conversation)) {
            return ['error' => [403, __("You don't have access to this conversation.")]];
        }
        if (!MailboxScope::allows($this->options, (int) ($conversation->mailbox_id ?? 0))) {
            return ['error' => [403, __("ARL League isn't turned on for this mailbox.")]];
        }

        $feature = $this->enabledFeature($this->stringInput($request, 'feature'));
        if ($feature === null || !(new ActionPermissions($this->options))->allows($user, $feature->key())) {
            return ['error' => [403, __(self::NO_PERMISSION)]];
        }

        $known = in_array($name, $isAction ? $feature->actionKeys() : $feature->queryKeys(), true);
        if (!$known) {
            return ['error' => [404, __("This action isn't available.")]];
        }

        $raw = $request->input('params', '{}');
        $params = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($params)) {
            return ['error' => [400, __("The action's details couldn't be read.")]];
        }

        if ($isAction && $feature instanceof GuardsActionParams) {
            $refusal = $feature->guardParams($name, $params, $this->customerEmails($conversation));
            if ($refusal !== null) {
                return ['error' => [403, $refusal]];
            }
        }

        return [
            'conversation' => $conversation,
            'user' => $user,
            'feature' => $feature,
            'name' => $name,
            'params' => $params,
        ];
    }

    /**
     * @return string[] The confirmation codes the dry run asked for.
     */
    private function allowedCodes(array $data): array
    {
        return array_values(array_unique(array_column($this->confirms($data), 'code')));
    }

    private function previewCacheKey(string $key): string
    {
        return 'spatbridge.preview.' . md5($key);
    }

    /**
     * Who previewed what: the agent, the conversation, the feature action
     * and its params (with keys sorted, so key order doesn't matter).
     */
    private function previewBinding(array $context): string
    {
        $params = $context['params'];
        $this->sortKeys($params);

        return sha1(implode('|', [
            (int) $context['user']->id,
            (int) $context['conversation']->id,
            $context['feature']->key(),
            $context['name'],
            $this->paramsJson($params),
        ]));
    }

    private function sortKeys(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortKeys($item);
            }
        }
    }

    private function stringInput(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return is_string($value) ? $value : '';
    }

    private function enabledFeature(string $key): ?Feature
    {
        foreach ($this->registry->enabled($this->options) as $feature) {
            if ($feature->key() === $key) {
                return $feature;
            }
        }

        return null;
    }

    private function runAction(array $context, string $key, bool $dryRun): ClientResult
    {
        $user = $context['user'];
        $email = trim((string) ($user->email ?? ''));
        $name = method_exists($user, 'getFullName') ? trim((string) $user->getFullName()) : '';

        return $this->client->action(
            $context['feature']->key(),
            $context['name'],
            $context['params'],
            $dryRun,
            $key,
            $email,
            $name === '' ? $email : $name
        );
    }

    /**
     * WordPress answers a repeated key with replayed=true. The note is still
     * written when this module never wrote one for the key, e.g. the first
     * Run timed out after WordPress had finished.
     *
     * @param string[] $changes
     * @return bool False when the note could not be written.
     */
    private function addNoteOnce(
        array $context,
        string $key,
        string $summary,
        array $changes,
        bool $replayed,
        ?string $followUp = null
    ): bool {
        $notedKey = 'spatbridge.noted.' . md5($key);
        if ($replayed && \Cache::get($notedKey) !== null) {
            return true;
        }

        try {
            $body = NoteWriter::actionBody($summary, $changes, $followUp);
            $this->notes->add($context['conversation'], $context['user'], $body);
            \Cache::put($notedKey, 1, self::NOTED_TTL);
        } catch (\Throwable $e) {
            $this->logger->error('[SpatBridge] adding the action note failed: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * The sidebar caches WordPress data per customer email for a minute;
     * after an action it must show the new state at once.
     *
     * @param object $conversation
     */
    private function forgetCustomerCache($conversation): void
    {
        foreach ($this->customerEmails($conversation) as $email) {
            \Cache::forget('spatbridge.customer.' . md5(strtolower($email)));
        }
    }

    /**
     * The addresses the sidebar looks the customer up by.
     *
     * @param object $conversation
     * @return string[]
     */
    private function customerEmails($conversation): array
    {
        $emails = [];
        $customer = $conversation->customer ?? null;
        if (is_object($customer) && method_exists($customer, 'getMainEmail')) {
            $emails[] = trim((string) $customer->getMainEmail());
        }
        $emails[] = trim((string) ($conversation->customer_email ?? ''));

        return array_values(array_unique(array_filter($emails, 'strlen')));
    }

    private function paramsJson(array $params): string
    {
        return $params === [] ? '{}' : (string) json_encode($params, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function text(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
    }

    /**
     * @return string[]
     */
    private function changes(array $data): array
    {
        $changes = [];
        foreach (isset($data['changes']) && is_array($data['changes']) ? $data['changes'] : [] as $change) {
            if (is_scalar($change) && trim((string) $change) !== '') {
                $changes[] = trim((string) $change);
            }
        }

        return $changes;
    }

    /**
     * @return array<int, array{code: string, message: string}>
     */
    private function confirms(array $data): array
    {
        $confirms = [];
        $items = isset($data['requires_confirm']) && is_array($data['requires_confirm'])
            ? $data['requires_confirm']
            : [];
        foreach ($items as $item) {
            $code = is_array($item) ? $this->text($item, 'code') : '';
            if ($code === '') {
                continue;
            }
            $message = $this->text($item, 'message');
            $confirms[] = ['code' => $code, 'message' => $message === '' ? $code : $message];
        }

        return $confirms;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'msg' => $message], $status);
    }

    protected function newIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    // @codeCoverageIgnoreStart
    //
    // Thin seams over Eloquent and FreeScout's view factory, replaced in the
    // unit tests and exercised by the e2e environment.

    /**
     * @return object|null
     */
    protected function findConversation(int $id)
    {
        return $id > 0 ? \App\Conversation::find($id) : null;
    }

    /**
     * @return mixed
     */
    protected function renderView(string $view, array $data, int $status)
    {
        return response()->view($view, $data, $status);
    }
    // @codeCoverageIgnoreEnd
}

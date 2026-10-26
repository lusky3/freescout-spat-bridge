<?php

namespace Modules\SpatBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\SpatBridge\Features\FeatureRegistry;
use Modules\SpatBridge\Services\ActionPermissions;
use Modules\SpatBridge\Services\ConnectionMessage;
use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\EventSettings;
use Modules\SpatBridge\Services\MailboxLookup;
use Modules\SpatBridge\Services\SpatClient;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;

class SpatBridgeSettingsController extends Controller
{
    /** @var OptionStoreInterface */
    private $options;
    /** @var FeatureRegistry */
    private $registry;

    /** @var MailboxLookup */
    private $mailboxLookup;
    /** @var EventCatalog */
    private $catalog;

    public function __construct(
        OptionStoreInterface $options,
        FeatureRegistry $registry,
        ?MailboxLookup $mailboxLookup = null,
        ?EventCatalog $catalog = null
    ) {
        $this->options = $options;
        $this->registry = $registry;
        $this->mailboxLookup = $mailboxLookup ?? new MailboxLookup();
        $this->catalog = $catalog ?? new EventCatalog();
    }

    public function save(Request $request)
    {
        // The all-bullets placeholder means "leave the secret alone". It is
        // normalised to empty BEFORE validation, otherwise min:32 rejects it.
        $rawSecret = (string) $request->input('shared_secret', '');
        if ($rawSecret !== '' && preg_match('/^•+$/u', $rawSecret)) {
            $request->merge(['shared_secret' => '']);
        }

        $validated = $request->validate([
            'wp_base_url' => 'required|url',
            'shared_secret' => 'nullable|string|min:32',
            'layout_default' => 'required|in:stacked,tabbed',
            'mailboxes' => 'nullable|array',
            'mailboxes.*' => 'integer',
            'feature_order' => 'nullable|array',
            'feature_order.*' => 'string',
            'actors' => 'nullable|array',
            'actors.*.mode' => 'required|in:' . implode(',', ActionPermissions::MODES),
            'actors.*.user_ids' => 'nullable|array',
            'actors.*.user_ids.*' => 'integer',
            'events_notes' => 'nullable',
            'events_tags' => 'nullable',
            'event_tag' => 'nullable|array',
            'event_tag.*' => ['nullable', 'string', 'max:100', 'regex:/^[\w\- ]+$/'],
            'event_create' => 'nullable|array',
            'events_mailbox_id' => 'nullable|integer',
            'events_note_user_id' => 'nullable|integer|min:0',
        ]);

        $createTypes = $this->createTypes($request);
        $eventMailboxId = (int) ($validated['events_mailbox_id'] ?? 0);
        if (($createTypes !== [] || $eventMailboxId !== 0) && !$this->mailboxLookup->exists($eventMailboxId)) {
            $validator = Container::getInstance()->make('validator')->make($request->all(), []);
            $validator->errors()->add(
                'events_mailbox_id',
                $createTypes !== []
                    ? __('Choose the mailbox new tickets go to.')
                    : __('That mailbox does not exist.')
            );

            throw new ValidationException($validator);
        }

        $this->options->set('spatbridge.wp_base_url', rtrim($validated['wp_base_url'], '/'));

        $submittedSecret = (string) ($validated['shared_secret'] ?? '');
        if ($submittedSecret !== '') {
            $this->options->set('spatbridge.shared_secret', $submittedSecret);
        }

        $this->options->set('spatbridge.layout_default', $validated['layout_default']);
        $this->options->set('spatbridge.allow_agent_switch', $request->has('allow_agent_switch'));

        $mailboxes = array_map('intval', (array) ($validated['mailboxes'] ?? []));
        $this->options->set('spatbridge.mailboxes', array_values(array_unique($mailboxes)));

        // Unchecked checkboxes are not submitted, so absence means off.
        $switches = (array) $request->input('features', []);
        $actors = (array) ($validated['actors'] ?? []);
        $known = [];
        foreach ($this->registry->all() as $feature) {
            $key = $feature->key();
            $known[] = $key;
            $this->options->set('spatbridge.features.' . $key . '.enabled', isset($switches[$key]));

            if (isset($actors[$key])) {
                $ids = array_map('intval', (array) ($actors[$key]['user_ids'] ?? []));
                $this->options->set('spatbridge.features.' . $key . '.actors', json_encode([
                    'mode' => $actors[$key]['mode'],
                    'user_ids' => array_values(array_unique($ids)),
                ]));
            }
        }

        $order = array_values(array_unique(array_intersect((array) ($validated['feature_order'] ?? []), $known)));
        $this->options->set('spatbridge.feature_order', $order);

        $this->saveEvents($request, $createTypes, $eventMailboxId, (int) ($validated['events_note_user_id'] ?? 0));

        return redirect()->back()->with('success', __('Settings saved.'));
    }

    /**
     * The catalog types whose "create a ticket" switch is on in the request.
     *
     * @return string[]
     */
    private function createTypes(Request $request): array
    {
        $switches = (array) $request->input('event_create', []);
        $types = [];
        foreach ($this->catalog->types() as $type) {
            if (isset($switches[EventSettings::fieldKey($type)])) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param string[] $createTypes
     */
    private function saveEvents(Request $request, array $createTypes, int $mailboxId, int $noteUserId): void
    {
        $this->options->set('spatbridge.events.notes', $request->has('events_notes'));
        $this->options->set('spatbridge.events.tags', $request->has('events_tags'));
        $this->options->set('spatbridge.events.mailbox_id', $mailboxId);
        $this->options->set('spatbridge.events.note_user_id', $noteUserId);

        $tags = (array) $request->input('event_tag', []);
        foreach ($this->catalog->types() as $type) {
            $key = EventSettings::fieldKey($type);
            $this->options->set('spatbridge.events.' . $type . '.create', in_array($type, $createTypes, true));
            if (isset($tags[$key])) {
                $this->options->set('spatbridge.events.' . $type . '.tag', trim((string) $tags[$key]));
            }
        }
    }

    public function test(SpatClient $client): JsonResponse
    {
        $result = $client->ping();
        $data = $result->data() ?: [];
        $providers = isset($data['providers']) && is_array($data['providers']) ? array_values($data['providers']) : [];

        return new JsonResponse([
            'ok' => $result->ok(),
            'message' => ConnectionMessage::describe($result),
            'latency_ms' => $result->latencyMs(),
            'spat_version' => $result->ok() && isset($data['spat_version']) ? (string) $data['spat_version'] : null,
            'providers' => $providers,
        ]);
    }
}

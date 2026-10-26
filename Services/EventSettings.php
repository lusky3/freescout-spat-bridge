<?php

namespace Modules\SpatBridge\Services;

use Modules\SpatBridge\Services\Support\OptionBool;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Modules\SpatBridge\Services\Support\Timezones;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What the "Events from WordPress" settings section shows. Defaults mirror
 * what EventRouter does when an option was never saved.
 */
class EventSettings
{
    /** @var OptionStoreInterface */
    private $options;
    /** @var EventCatalog */
    private $catalog;
    /** @var TagService */
    private $tags;
    /** @var EventStoreInterface */
    private $store;
    /** @var LoggerInterface */
    private $logger;
    /** @var callable(): \DateTimeZone */
    private $timezone;

    /**
     * @param callable|null $timezone Returns the zone to show times in; the
     *        viewing agent's FreeScout time zone by default.
     */
    public function __construct(
        OptionStoreInterface $options,
        EventCatalog $catalog,
        TagService $tags,
        EventStoreInterface $store,
        ?LoggerInterface $logger = null,
        ?callable $timezone = null
    ) {
        $this->options = $options;
        $this->catalog = $catalog;
        $this->tags = $tags;
        $this->store = $store;
        $this->logger = $logger ?? new NullLogger();
        $this->timezone = $timezone ?? [Timezones::class, 'agent'];
    }

    /**
     * The form field key for a type: dots are easy to mangle in a field
     * name, so "etransfer.unmatched" is "etransfer_unmatched".
     */
    public static function fieldKey(string $type): string
    {
        return str_replace('.', '_', $type);
    }

    /**
     * @return array{
     *     notes: bool, tags: bool, mailboxId: int, noteUserId: int, tagsAvailable: bool, tableMissing: bool,
     *     types: array<int, array{
     *         type: string, key: string, label: string, tag: string, canCreate: bool, create: bool
     *     }>,
     *     lastEvent: array{time: string, type: string, email: string, outcome: string}|null
     * }
     */
    public function build(): array
    {
        $types = [];
        foreach ($this->catalog->types() as $type) {
            $types[] = [
                'type' => $type,
                'key' => self::fieldKey($type),
                'label' => $this->catalog->label($type),
                'tag' => $this->tag($type),
                'canCreate' => $this->catalog->canCreate($type),
                'create' => $this->catalog->canCreate($type)
                    && OptionBool::from($this->options->get('spatbridge.events.' . $type . '.create'), false),
            ];
        }

        $tableMissing = false;
        $lastEvent = $this->lastEvent($tableMissing);

        return [
            'notes' => OptionBool::from($this->options->get('spatbridge.events.notes'), true),
            'tags' => OptionBool::from($this->options->get('spatbridge.events.tags'), true),
            'mailboxId' => (int) $this->options->get('spatbridge.events.mailbox_id', 0),
            'noteUserId' => (int) $this->options->get('spatbridge.events.note_user_id', 0),
            'tagsAvailable' => $this->tags->available(),
            'types' => $types,
            'lastEvent' => $lastEvent,
            'tableMissing' => $tableMissing,
        ];
    }

    private function tag(string $type): string
    {
        $saved = $this->options->get('spatbridge.events.' . $type . '.tag');
        if ($saved === null) {
            $default = $this->catalog->defaultTag($type);

            return $default['add'] ?? '';
        }

        return is_scalar($saved) ? (string) $saved : '';
    }

    /**
     * @return array{time: string, type: string, email: string, outcome: string}|null
     */
    private function lastEvent(bool &$tableMissing): ?array
    {
        try {
            $rows = $this->store->latest(1);
        } catch (\Throwable $e) {
            // Typically an upgraded install that hasn't run the migration;
            // logged in case it's a database outage instead.
            $this->logger->warning('[SpatBridge] reading the last event failed: ' . $e->getMessage(), [
                'page' => 'settings',
                'exception_class' => get_class($e),
            ]);
            $tableMissing = true;

            return null;
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return [
            'time' => $this->agentTime($row['created_at']),
            'type' => $this->catalog->label($row['type']),
            'email' => $row['email'],
            'outcome' => $this->catalog->outcomeLabel($row['outcome']),
        ];
    }

    /**
     * Rows are stamped in the app zone; show the agent's zone, labelled.
     */
    private function agentTime(string $stored): string
    {
        try {
            return (new \DateTimeImmutable($stored, Timezones::app()))
                ->setTimezone(($this->timezone)())
                ->format('Y-m-d H:i:s T');
        } catch (\Exception $e) {
            return $stored;
        }
    }
}

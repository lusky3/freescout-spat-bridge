<?php

namespace Modules\SpatBridge\Services;

use Modules\SpatBridge\Services\Support\OptionStoreInterface;

/**
 * Who may run a feature's row actions. Stored per feature as a JSON string
 * `{"mode":"admins|users|selected","user_ids":[…]}` under
 * `spatbridge.features.{key}.actors`; anything unreadable falls back to the
 * feature's default.
 *
 * `users` means every active user. `selected` means the listed users plus
 * admins, so an admin is never locked out.
 */
final class ActionPermissions
{
    public const MODES = ['admins', 'users', 'selected'];

    private const DEFAULTS = ['player' => 'admins', 'etransfers' => 'admins', 'waitlist' => 'users'];

    /** @var OptionStoreInterface */
    private $options;

    public function __construct(OptionStoreInterface $options)
    {
        $this->options = $options;
    }

    /**
     * @param object|null $user Needs isAdmin(): bool and a public id.
     */
    public function allows($user, string $featureKey): bool
    {
        if (!is_object($user)) {
            return false;
        }

        $mode = $this->mode($featureKey);
        if ($mode === 'users') {
            return true;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $mode === 'selected' && in_array((int) $user->id, $this->selectedUserIds($featureKey), true);
    }

    public function mode(string $featureKey): string
    {
        $stored = $this->stored($featureKey);

        return $stored['mode'] ?? self::DEFAULTS[$featureKey] ?? 'admins';
    }

    /**
     * @return int[]
     */
    public function selectedUserIds(string $featureKey): array
    {
        $stored = $this->stored($featureKey);
        if (!isset($stored['user_ids']) || !is_array($stored['user_ids'])) {
            return [];
        }

        $ids = [];
        foreach ($stored['user_ids'] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function stored(string $featureKey): array
    {
        $raw = $this->options->get('spatbridge.features.' . $featureKey . '.actors', '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        // Without a valid mode the whole entry is unusable, ids included.
        return is_array($decoded)
            && isset($decoded['mode'])
            && is_string($decoded['mode'])
            && in_array($decoded['mode'], self::MODES, true)
            ? $decoded
            : [];
    }
}

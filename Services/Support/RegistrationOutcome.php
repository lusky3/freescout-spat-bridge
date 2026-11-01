<?php

namespace Modules\SpatBridge\Services\Support;

/**
 * Readable text for the registration outcome codes SPAT logs. Codes without
 * a label here keep the plain "underscores to spaces" reading, so a code SPAT
 * adds later still shows something sensible.
 *
 * The identity codes come from SPAT's check of a name-only match against the
 * record it found (SPPR_Identity_Check):
 * - player_found_by_name_identity_conflict: the record belongs to someone
 *   else; nothing was written and the order stays open (a failure).
 * - *_unverified: nothing to compare, so the order registered on the name
 *   alone; logged for audit.
 * - user_link_refused_identity_conflict: the record is linked to another
 *   account, so the link was left alone; the registration itself stands.
 */
final class RegistrationOutcome
{
    public static function label(string $code): string
    {
        switch ($code) {
            case 'player_found_by_name_identity_conflict':
                return __('name match conflicts with the account or email on the record, needs manual review');
            case 'player_found_by_name_unverified':
            case 'player_found_by_normalized_name_unverified':
                return __('matched by name only, not verified');
            case 'user_link_refused_identity_conflict':
                return __('account link refused, the player belongs to another account');
            default:
                return str_replace('_', ' ', $code);
        }
    }
}

<?php

namespace Modules\SpatBridge\Services;

/**
 * HMAC-SHA256 request signing shared with the SPAT WordPress side.
 *
 * Signature = lower-case hex of hash_hmac('sha256', "{timestamp}.{rawBody}", secret).
 */
final class Signer
{
    public const HEADER_TIMESTAMP = 'X-SPAT-Timestamp';
    public const HEADER_SIGNATURE = 'X-SPAT-Signature';

    public static function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * True when the signature matches and the timestamp is within +/- $window seconds of $now (inclusive).
     */
    public static function verify(
        string $timestamp,
        string $signature,
        string $rawBody,
        string $secret,
        int $now,
        int $window = 300
    ): bool {
        if ($secret === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs($now - (int) $timestamp) > $window) {
            return false;
        }

        return hash_equals(self::sign($timestamp, $rawBody, $secret), $signature);
    }
}

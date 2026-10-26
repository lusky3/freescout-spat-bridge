<?php

namespace Modules\SpatBridge\Services;

/**
 * Plain-words description of a ping result, for the Test connection button.
 */
final class ConnectionMessage
{
    public static function describe(ClientResult $result): string
    {
        if ($result->ok()) {
            $data = $result->data() ?: [];
            $version = '?';
            if (isset($data['spat_version']) && is_scalar($data['spat_version'])) {
                $version = (string) $data['spat_version'];
            }

            return 'Connected. SPAT ' . $version . ', ' . $result->latencyMs() . ' ms.';
        }

        switch ($result->errorCode()) {
            case 'not_configured':
                return 'Enter the WordPress site URL and shared secret, then save.';
            case 'wp_not_configured':
                return 'WordPress has no FreeScout secret set. Add it under SportsPress Admin Tools → FreeScout.';
            case 'bad_signature':
                return 'WordPress rejected the signature. Check that both sides use the same secret.';
            case 'clock_skew':
                return "WordPress says the request's timestamp is too far off. "
                    . "Check this server's clock; it must be within 5 minutes of WordPress.";
            case 'rate_limited':
                return 'WordPress is rate-limiting requests. Try again in a minute.';
            case 'network':
                return "Couldn't reach WordPress. Check the site URL.";
            case 'timeout':
                return "WordPress didn't answer within 3 seconds.";
            case 'http_error':
                return 'WordPress returned an error (HTTP ' . $result->status() . ').';
            case 'bad_response':
                return 'WordPress answered, but not with the expected data. Is SportsPress Admin Tools up to date?';
            default:
                return "Couldn't reach WordPress.";
        }
    }
}

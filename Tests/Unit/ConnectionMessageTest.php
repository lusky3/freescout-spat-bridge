<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\ConnectionMessage;
use PHPUnit\Framework\TestCase;

class ConnectionMessageTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function errorCopy(): array
    {
        return [
            ['not_configured', 'Enter the WordPress site URL and shared secret, then save.'],
            ['wp_not_configured', 'WordPress has no FreeScout secret set. '
                . 'Add it under SportsPress Admin Tools → FreeScout.'],
            ['bad_signature', 'WordPress rejected the signature. Check that both sides use the same secret.'],
            ['clock_skew', "WordPress says the request's timestamp is too far off. "
                . "Check this server's clock; it must be within 5 minutes of WordPress."],
            ['rate_limited', 'WordPress is rate-limiting requests. Try again in a minute.'],
            ['network', "Couldn't reach WordPress. Check the site URL."],
            ['timeout', "WordPress didn't answer within 3 seconds."],
            ['bad_response', 'WordPress answered, but not with the expected data. '
                . 'Is SportsPress Admin Tools up to date?'],
        ];
    }

    /**
     * @dataProvider errorCopy
     */
    public function test_error_code_copy(string $code, string $expected): void
    {
        $this->assertSame($expected, ConnectionMessage::describe(ClientResult::failure($code)));
    }

    public function test_http_error_includes_the_status(): void
    {
        $this->assertSame(
            'WordPress returned an error (HTTP 502).',
            ConnectionMessage::describe(ClientResult::failure('http_error', 502))
        );
    }

    public function test_ok_includes_version_and_latency(): void
    {
        $result = ClientResult::success(200, ['spat_version' => '1.1.3', 'providers' => []], 142);

        $this->assertSame('Connected. SPAT 1.1.3, 142 ms.', ConnectionMessage::describe($result));
    }

    public function test_unknown_error_code_falls_back_to_a_generic_line(): void
    {
        $this->assertSame("Couldn't reach WordPress.", ConnectionMessage::describe(ClientResult::failure('weird')));
    }
}

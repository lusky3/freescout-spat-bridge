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
            ['spat_outdated', "WordPress answered, but SPAT doesn't have the FreeScout routes. "
                . 'Update SportsPress Admin Tools.'],
            ['redirected', 'WordPress redirected the request. Check the site URL.'],
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

    public function test_redirected_names_the_target(): void
    {
        $this->assertSame(
            'WordPress redirected to https://www.wp.example. Use that URL as the site URL.',
            ConnectionMessage::describe(ClientResult::failure('redirected', 301, 12, 'https://www.wp.example'))
        );
    }

    /**
     * @return array<string, array{0: ClientResult, 1: string}>
     */
    public function actionCopy(): array
    {
        return [
            'actor unknown shows the WP message' => [
                ClientResult::failure('actor_unknown', 403, 0, 'No WordPress account for agent@x.test'),
                'No WordPress account for agent@x.test',
            ],
            'actor unknown without a message' => [
                ClientResult::failure('actor_unknown', 403),
                'No WordPress account for your email address.',
            ],
            'actor forbidden' => [
                ClientResult::failure('actor_forbidden', 403, 0, 'whatever WP says'),
                "Your WordPress account isn't allowed to do this.",
            ],
            'unknown action' => [
                ClientResult::failure('unknown_action', 404),
                "WordPress doesn't support this action. Update SportsPress Admin Tools.",
            ],
            'action error is verbatim' => [
                ClientResult::failure('action_error', 409, 0, 'Amounts differ: e-Transfer $300.00, order $325.00'),
                'Amounts differ: e-Transfer $300.00, order $325.00',
            ],
            'actions wait 15 seconds' => [
                ClientResult::failure('timeout'),
                "WordPress didn't answer within 15 seconds.",
            ],
            'transport errors reuse the connection copy' => [
                ClientResult::failure('network'),
                "Couldn't reach WordPress. Check the site URL.",
            ],
            'http error reuses the connection copy' => [
                ClientResult::failure('http_error', 502),
                'WordPress returned an error (HTTP 502).',
            ],
        ];
    }

    /**
     * @dataProvider actionCopy
     */
    public function test_action_message_copy(ClientResult $result, string $expected): void
    {
        $this->assertSame($expected, ConnectionMessage::actionMessage($result));
    }
}

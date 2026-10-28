<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\Signer;
use PHPUnit\Framework\TestCase;

class SignerTest extends TestCase
{
    public function test_sign_matches_wp_formula(): void
    {
        $this->assertSame(
            hash_hmac('sha256', '1700000000.{"a":1}', 's'),
            Signer::sign('1700000000', '{"a":1}', 's')
        );
    }

    public function test_verify_accepts_valid_signature_inside_window(): void
    {
        $ts = '1700000000';
        $body = '{"a":1}';
        $sig = Signer::sign($ts, $body, 's');

        $this->assertTrue(Signer::verify($ts, $sig, $body, 's', 1700000000 + 300));
    }

    public function test_verify_rejects_outside_window(): void
    {
        $ts = '1700000000';
        $body = '{"a":1}';
        $sig = Signer::sign($ts, $body, 's');

        $this->assertFalse(Signer::verify($ts, $sig, $body, 's', 1700000000 + 301));
        $this->assertFalse(Signer::verify($ts, $sig, $body, 's', 1700000000 - 301));
    }

    public function test_verify_rejects_tampered_body_and_empty_or_non_numeric_inputs(): void
    {
        $ts = '1700000000';
        $body = '{"a":1}';
        $sig = Signer::sign($ts, $body, 's');
        $now = 1700000000;

        $this->assertFalse(Signer::verify($ts, $sig, '{"a":2}', 's', $now), 'tampered body');
        $this->assertFalse(Signer::verify($ts, '', $body, 's', $now), 'empty signature');
        $this->assertFalse(Signer::verify('abc', $sig, $body, 's', $now), 'non-numeric timestamp');
        $this->assertFalse(Signer::verify($ts, $sig, $body, '', $now), 'empty secret');
    }

    public function test_header_constants_are_spat_headers(): void
    {
        $this->assertSame('X-SPAT-Timestamp', Signer::HEADER_TIMESTAMP);
        $this->assertSame('X-SPAT-Signature', Signer::HEADER_SIGNATURE);
    }
}

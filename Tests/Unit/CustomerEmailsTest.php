<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\Support\CustomerEmails;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCustomer;

/**
 * Ported from SplmWaitlist's customerEmails() tests: the addresses a
 * customer is looked up by, which the sidebar, its cache and the action
 * guard all share.
 */
class CustomerEmailsTest extends TestCase
{
    public function test_collects_main_then_conversation_then_other_addresses(): void
    {
        $customer = new FakeCustomer('main@example.com', ['main@example.com', 'second@example.com']);
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $this->assertSame(
            ['main@example.com', 'conversation@example.com', 'second@example.com'],
            CustomerEmails::of($customer, $conversation)
        );
    }

    public function test_addresses_are_trimmed_lower_cased_and_de_duplicated(): void
    {
        $customer = new FakeCustomer('  Main@Example.com ', ['MAIN@example.com']);
        $conversation = (object) ['customer_email' => 'main@example.com'];

        $this->assertSame(['main@example.com'], CustomerEmails::of($customer, $conversation));
    }

    public function test_uses_the_conversation_address_when_the_customer_has_no_methods(): void
    {
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $this->assertSame(['conversation@example.com'], CustomerEmails::of(new \stdClass(), $conversation));
        $this->assertSame(['conversation@example.com'], CustomerEmails::of(null, $conversation));
    }

    public function test_skips_a_blank_main_email(): void
    {
        $customer = new FakeCustomer('', ['', null]);
        $conversation = (object) ['customer_email' => 'conversation@example.com'];

        $this->assertSame(['conversation@example.com'], CustomerEmails::of($customer, $conversation));
    }

    public function test_is_empty_when_no_source_has_one(): void
    {
        $this->assertSame([], CustomerEmails::of(null, new \stdClass()));
        $this->assertSame([], CustomerEmails::of(null, 'not-an-object'));
        $this->assertSame([], CustomerEmails::of(new FakeCustomer(null), null));
    }

    public function test_caps_the_list_at_five_with_the_main_address_first(): void
    {
        $all = [];
        for ($i = 1; $i <= 9; $i++) {
            $all[] = "e{$i}@example.com";
        }
        $customer = new FakeCustomer($all[3], $all);

        $this->assertSame(
            ['e4@example.com', 'thread@example.com', 'e1@example.com', 'e2@example.com', 'e3@example.com'],
            CustomerEmails::of($customer, (object) ['customer_email' => 'thread@example.com'])
        );
        $this->assertSame(5, CustomerEmails::MAX);
    }

    public function test_drops_entries_that_are_not_plausible_addresses(): void
    {
        $customer = new FakeCustomer('not an address', ['main@example.com', 'two@@example.com', 'x@', ['a@b.c']]);
        $conversation = (object) ['customer_email' => 'Thread@Example.com '];

        $this->assertSame(['thread@example.com', 'main@example.com'], CustomerEmails::of($customer, $conversation));
    }

    public function test_of_conversation_reads_the_conversations_own_customer(): void
    {
        $conversation = (object) [
            'customer_email' => 'From@Example.com',
            'customer' => new FakeCustomer('main@example.com', ['main@example.com', 'other@example.com']),
        ];

        $this->assertSame(
            ['main@example.com', 'from@example.com', 'other@example.com'],
            CustomerEmails::ofConversation($conversation)
        );
        $this->assertSame([], CustomerEmails::ofConversation(null));
    }

    public function test_the_cache_key_is_one_per_address_list(): void
    {
        $key = CustomerEmails::cacheKey(['a@example.com', 'b@example.com']);

        $this->assertStringStartsWith('spatbridge.customer.', $key);
        $this->assertSame($key, CustomerEmails::cacheKey(['a@example.com', 'b@example.com']));
        // The first address is the primary WordPress reports back, so order counts.
        $this->assertNotSame($key, CustomerEmails::cacheKey(['b@example.com', 'a@example.com']));
        $this->assertNotSame($key, CustomerEmails::cacheKey(['a@example.com']));
        $this->assertNotSame(
            CustomerEmails::cacheKey(['a@example.com,b@example.com']),
            CustomerEmails::cacheKey(['a@example.com', 'b@example.com'])
        );
    }
}

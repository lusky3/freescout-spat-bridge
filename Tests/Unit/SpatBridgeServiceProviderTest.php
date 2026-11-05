<?php

namespace Tests\Unit;

use Modules\SpatBridge\Providers\SpatBridgeServiceProvider;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\SpatClient;
use Modules\SpatBridge\Services\Support\CustomerEmails;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\FakeApp;
use Tests\Support\FakeCache;
use Tests\Support\FakeCustomer;
use Tests\Support\FakeOptionStore;

class SpatBridgeServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        FakeCache::reset();
    }

    private function provider(?FakeOptionStore $options = null, ?SpatClient $client = null): SpatBridgeServiceProvider
    {
        $app = new FakeApp();
        $app->bind(OptionStoreInterface::class, $options ?: new FakeOptionStore());
        if ($client !== null) {
            $app->bind(SpatClient::class, $client);
        }

        return new SpatBridgeServiceProvider($app);
    }

    private function invoke(SpatBridgeServiceProvider $provider, string $method, array $args)
    {
        $ref = new ReflectionMethod($provider, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($provider, $args);
    }

    private function client(array $results): SpatClient
    {
        return new class ($results) extends SpatClient {
            public $calls = 0;
            private $results;

            public function __construct(array $results)
            {
                $this->results = $results;
            }

            /** @var array<int, string[]> */
            public $sent = [];

            public function customer(array $emails): ClientResult
            {
                $this->sent[] = $emails;

                return $this->results[$this->calls++];
            }
        };
    }

    public function test_should_show_for_mailbox_when_list_empty_or_contains_id(): void
    {
        $options = new FakeOptionStore();
        $provider = $this->provider($options);

        $this->assertTrue($this->invoke($provider, 'shouldShowForMailbox', [5]));

        $options->set('spatbridge.mailboxes', []);
        $this->assertTrue($this->invoke($provider, 'shouldShowForMailbox', [5]));

        $options->set('spatbridge.mailboxes', [3, '5']);
        $this->assertTrue($this->invoke($provider, 'shouldShowForMailbox', [5]));
        $this->assertFalse($this->invoke($provider, 'shouldShowForMailbox', [6]));
    }

    public function test_events_mailbox_always_shows_the_panel(): void
    {
        $options = new FakeOptionStore();
        $provider = $this->provider($options);
        $options->set('spatbridge.mailboxes', [3]);
        $options->set('spatbridge.events.mailbox_id', '8');

        $this->assertTrue($this->invoke($provider, 'shouldShowForMailbox', [8]));
        $this->assertTrue($this->invoke($provider, 'shouldShowForMailbox', [3]));
        $this->assertFalse($this->invoke($provider, 'shouldShowForMailbox', [6]));
    }

    public function test_the_lookup_sends_every_customer_address_and_caches_under_the_lists_key(): void
    {
        $client = $this->client([ClientResult::success(200, ['sections' => ['x' => 1]], 7)]);
        $provider = $this->provider(null, $client);
        $customer = new FakeCustomer('Jordan@Example.com', ['jordan@example.com', 'second@example.com']);
        $conversation = (object) ['customer_email' => 'thread@example.com'];
        $emails = ['jordan@example.com', 'thread@example.com', 'second@example.com'];

        $first = $this->invoke($provider, 'cachedResult', [CustomerEmails::of($customer, $conversation)]);
        $second = $this->invoke($provider, 'cachedResult', [$emails]);

        $this->assertTrue($first->ok());
        $this->assertTrue($second->ok());
        $this->assertSame(['sections' => ['x' => 1]], $second->data());
        $this->assertSame([$emails], $client->sent);
        $this->assertArrayHasKey(CustomerEmails::cacheKey($emails), FakeCache::$store);
    }

    public function test_another_address_list_is_another_lookup(): void
    {
        $client = $this->client([
            ClientResult::success(200, ['sections' => []], 1),
            ClientResult::success(200, ['sections' => []], 1),
        ]);
        $provider = $this->provider(null, $client);

        $this->invoke($provider, 'cachedResult', [['a@example.com']]);
        $this->invoke($provider, 'cachedResult', [['a@example.com', 'b@example.com']]);

        $this->assertSame([['a@example.com'], ['a@example.com', 'b@example.com']], $client->sent);
    }

    public function test_failures_are_not_cached(): void
    {
        $client = $this->client([
            ClientResult::failure('timeout'),
            ClientResult::success(200, ['sections' => []], 1),
        ]);
        $provider = $this->provider(null, $client);

        $this->assertFalse($this->invoke($provider, 'cachedResult', [['a@example.com']])->ok());
        $this->assertTrue($this->invoke($provider, 'cachedResult', [['a@example.com']])->ok());
        $this->assertSame(2, $client->calls);
    }
}

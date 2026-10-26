<?php

namespace Tests\Unit;

use Modules\SpatBridge\Providers\SpatBridgeServiceProvider;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\SpatClient;
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

            public function customer(string $email): ClientResult
            {
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

    public function test_customer_email_prefers_customer_then_conversation(): void
    {
        $provider = $this->provider();
        $conversation = (object) ['customer_email' => 'conv@example.com'];

        $email = function ($customer, $conv) use ($provider) {
            return $this->invoke($provider, 'customerEmail', [$customer, $conv]);
        };

        $this->assertSame('a@example.com', $email(new FakeCustomer(' a@example.com '), $conversation));
        $this->assertSame('conv@example.com', $email(new FakeCustomer(''), $conversation));
        $this->assertSame('conv@example.com', $email(null, $conversation));
        $this->assertSame('', $email(null, null));
    }

    public function test_ok_results_are_cached_by_lowercased_email(): void
    {
        $client = $this->client([ClientResult::success(200, ['sections' => ['x' => 1]], 7)]);
        $provider = $this->provider(null, $client);

        $first = $this->invoke($provider, 'cachedResult', ['Jordan@Example.com']);
        $second = $this->invoke($provider, 'cachedResult', ['jordan@example.com']);

        $this->assertTrue($first->ok());
        $this->assertTrue($second->ok());
        $this->assertSame(['sections' => ['x' => 1]], $second->data());
        $this->assertSame(1, $client->calls);
        $this->assertArrayHasKey('spatbridge.customer.' . md5('jordan@example.com'), FakeCache::$store);
    }

    public function test_failures_are_not_cached(): void
    {
        $client = $this->client([
            ClientResult::failure('timeout'),
            ClientResult::success(200, ['sections' => []], 1),
        ]);
        $provider = $this->provider(null, $client);

        $this->assertFalse($this->invoke($provider, 'cachedResult', ['a@example.com'])->ok());
        $this->assertTrue($this->invoke($provider, 'cachedResult', ['a@example.com'])->ok());
        $this->assertSame(2, $client->calls);
    }
}

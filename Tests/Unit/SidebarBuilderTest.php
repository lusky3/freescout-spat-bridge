<?php

namespace Tests\Unit;

use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\SidebarBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class SidebarBuilderTest extends TestCase
{
    private function feature(string $key, $panel): Feature
    {
        return new class ($key, $panel) implements Feature {
            private $key;
            private $panel;

            public function __construct(string $key, $panel)
            {
                $this->key = $key;
                $this->panel = $panel;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return $this->key;
            }

            public function providerKeys(): array
            {
                return [];
            }

            public function present(array $sections): ?Panel
            {
                if ($this->panel instanceof \Throwable) {
                    throw $this->panel;
                }

                return $this->panel;
            }
        };
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            public $records = [];

            public function log($level, $message, array $context = [])
            {
                $this->records[] = [$level, (string) $message];
            }
        };
    }

    private function ok(): ClientResult
    {
        return ClientResult::success(200, ['sections' => []], 5);
    }

    public function test_failed_result_is_unavailable_not_empty(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build(ClientResult::failure('timeout'), [$this->feature('a', new Panel('a', 'A', []))]);

        $this->assertSame('unavailable', $vm['state']);
        $this->assertSame([], $vm['panels']);
    }

    public function test_all_features_return_null_is_empty(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->ok(), [$this->feature('a', null), $this->feature('b', null)]);

        $this->assertSame('empty', $vm['state']);
        $this->assertSame([], $vm['panels']);
        $this->assertNull($vm['summary']);
        $this->assertNull($vm['activeTab']);
    }

    public function test_active_tab_is_first_panel_needing_attention(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->ok(), [
            $this->feature('player', new Panel('player', 'Player', [])),
            $this->feature('registration', new Panel('registration', 'Registration', [], null, true)),
        ]);

        $this->assertSame('ok', $vm['state']);
        $this->assertCount(2, $vm['panels']);
        $this->assertSame('registration', $vm['activeTab']);
    }

    public function test_active_tab_defaults_to_first_panel(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->ok(), [
            $this->feature('player', new Panel('player', 'Player', [])),
            $this->feature('registration', new Panel('registration', 'Registration', [])),
        ]);

        $this->assertSame('player', $vm['activeTab']);
    }

    public function test_summary_comes_from_first_panel_that_has_one(): void
    {
        $builder = new SidebarBuilder($this->logger());
        $vm = $builder->build($this->ok(), [
            $this->feature('a', new Panel('a', 'A', [])),
            $this->feature('b', new Panel('b', 'B', [], 'Ducks, Div 3')),
            $this->feature('c', new Panel('c', 'C', [], 'later')),
        ]);

        $this->assertSame('Ducks, Div 3', $vm['summary']);
    }

    public function test_feature_that_throws_is_skipped_others_still_render(): void
    {
        $logger = $this->logger();
        $builder = new SidebarBuilder($logger);
        $vm = $builder->build($this->ok(), [
            $this->feature('bad', new \RuntimeException('boom')),
            $this->feature('good', new Panel('good', 'Good', [])),
        ]);

        $this->assertSame('ok', $vm['state']);
        $this->assertCount(1, $vm['panels']);
        $this->assertSame('good', $vm['panels'][0]->key());
        $this->assertCount(1, $logger->records);
        $this->assertStringContainsString('[SpatBridge]', $logger->records[0][1]);
        $this->assertStringContainsString('boom', $logger->records[0][1]);
    }
}

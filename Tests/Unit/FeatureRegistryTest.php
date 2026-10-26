<?php

namespace Tests\Unit;

use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\FeatureRegistry;
use Modules\SpatBridge\Features\Panel;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeOptionStore;

class FeatureRegistryTest extends TestCase
{
    private function feature(string $key): Feature
    {
        return new class ($key) implements Feature {
            private $key;

            public function __construct(string $key)
            {
                $this->key = $key;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Label ' . $this->key;
            }

            public function providerKeys(): array
            {
                return [];
            }

            public function present(array $sections): ?Panel
            {
                return null;
            }
        };
    }

    private function keys(array $features): array
    {
        return array_map(function (Feature $f) {
            return $f->key();
        }, $features);
    }

    public function test_enabled_respects_switch_and_order(): void
    {
        $registry = new FeatureRegistry();
        $a = $this->feature('A');
        $b = $this->feature('B');
        $c = $this->feature('C');
        $registry->register($a);
        $registry->register($b);
        $registry->register($c);

        $options = new FakeOptionStore();
        $options->set('spatbridge.features.B.enabled', false);
        $options->set('spatbridge.feature_order', ['C', 'A']);

        $this->assertSame(['C', 'A'], $this->keys($registry->enabled($options)));
    }

    public function test_unknown_feature_in_order_is_ignored_and_unlisted_feature_goes_last(): void
    {
        $registry = new FeatureRegistry();
        $registry->register($this->feature('A'));
        $registry->register($this->feature('B'));
        $registry->register($this->feature('C'));

        $options = new FakeOptionStore();
        $options->set('spatbridge.feature_order', ['Nope', 'C']);

        $this->assertSame(['C', 'A', 'B'], $this->keys($registry->enabled($options)));
    }

    public function test_all_returns_features_in_registration_order(): void
    {
        $registry = new FeatureRegistry();
        $registry->register($this->feature('B'));
        $registry->register($this->feature('A'));

        $this->assertSame(['B', 'A'], $this->keys($registry->all()));
    }

    public function test_only_explicit_false_disables_and_non_array_order_is_empty(): void
    {
        $registry = new FeatureRegistry();
        $registry->register($this->feature('A'));
        $registry->register($this->feature('B'));

        $options = new FakeOptionStore();
        $options->set('spatbridge.features.A.enabled', true);
        $options->set('spatbridge.features.B.enabled', 'no');
        $options->set('spatbridge.feature_order', 'B,A');

        $this->assertSame(['A', 'B'], $this->keys($registry->enabled($options)));
    }
}

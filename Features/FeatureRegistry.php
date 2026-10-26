<?php

namespace Modules\SpatBridge\Features;

use Modules\SpatBridge\Services\Support\OptionStoreInterface;

final class FeatureRegistry
{
    /** @var Feature[] */
    private $features = [];

    public function register(Feature $feature): void
    {
        $this->features[] = $feature;
    }

    /**
     * @return Feature[] In registration order.
     */
    public function all(): array
    {
        return $this->features;
    }

    /**
     * Features that are switched on, ordered by `spatbridge.feature_order`.
     * Only an explicit `false` switches a feature off. Features missing from
     * the order keep their registration order and come last.
     *
     * @return Feature[]
     */
    public function enabled(OptionStoreInterface $options): array
    {
        $enabled = [];
        foreach ($this->features as $feature) {
            $switch = $options->get('spatbridge.features.' . $feature->key() . '.enabled', true);
            if ($switch !== false) {
                $enabled[] = $feature;
            }
        }

        $rank = $this->rankByKey($options->get('spatbridge.feature_order', []));

        $positions = [];
        foreach ($enabled as $position => $feature) {
            $positions[] = [
                'feature' => $feature,
                'rank' => isset($rank[$feature->key()]) ? $rank[$feature->key()] : PHP_INT_MAX,
                'position' => $position,
            ];
        }

        // usort is not stable on PHP 7.4, so registration position breaks ties.
        usort($positions, function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] < $b['rank'] ? -1 : 1;
            }

            return $a['position'] - $b['position'];
        });

        return array_map(function (array $entry): Feature {
            return $entry['feature'];
        }, $positions);
    }

    /**
     * @param mixed $order
     * @return array<string, int> Feature key => position in the configured order.
     */
    private function rankByKey($order): array
    {
        if (!is_array($order)) {
            return [];
        }

        $rank = [];
        $position = 0;
        foreach ($order as $key) {
            if (!is_string($key) || isset($rank[$key])) {
                continue;
            }
            $rank[$key] = $position;
            $position++;
        }

        return $rank;
    }
}

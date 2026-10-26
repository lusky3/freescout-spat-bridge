<?php

namespace Modules\SpatBridge\Features;

/**
 * A piece of league info shown in the FreeScout conversation sidebar.
 *
 * A feature reads the WordPress sections it needs and turns them into a
 * neutral Panel; the core decides how to render that Panel.
 */
interface Feature
{
    public function key(): string;

    public function label(): string;

    /**
     * The WordPress section keys this feature reads.
     *
     * @return string[]
     */
    public function providerKeys(): array;

    /**
     * @param array $sections The response's `sections` map.
     * @return Panel|null Null when there is nothing to show for this customer.
     */
    public function present(array $sections): ?Panel;
}

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
     * @param string[] $emails The customer addresses WordPress looked up,
     *        primary first (the response's `emails`).
     * @return Panel|null Null when there is nothing to show for this customer.
     */
    public function present(array $sections, array $emails = []): ?Panel;

    /**
     * The row actions this feature offers, e.g. ['match']. The action
     * endpoints refuse any feature/action pair not listed here.
     *
     * @return string[]
     */
    public function actionKeys(): array;

    /**
     * The read-only queries a pop-up of this feature may run, e.g.
     * ['search']. The query endpoint refuses any name not listed here.
     *
     * @return string[]
     */
    public function queryKeys(): array;

    /**
     * A view to show in the pop-up instead of the dry-run preview, e.g. a
     * picker when the action still lacks a parameter.
     *
     * @return array{view: string, data: array}|null Null shows the dry-run preview.
     */
    public function previewOverride(string $action, array $params): ?array;

    /**
     * Turns the confirmation codes the agent ticked in the preview into the
     * params WordPress expects, e.g. amount_mismatch → force_mismatch.
     *
     * @param string[] $codes
     * @return array The params to run the action with.
     */
    public function mapConfirmCodes(string $action, array $codes, array $params): array;
}

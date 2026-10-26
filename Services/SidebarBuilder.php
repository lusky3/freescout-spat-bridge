<?php

namespace Modules\SpatBridge\Services;

use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\Panel;
use Modules\SpatBridge\Features\PanelRow;
use Psr\Log\LoggerInterface;

/**
 * Turns a SPAT response plus the enabled features into the view model the
 * sidebar templates render. A failed response is "unavailable", never
 * "empty", so agents can tell a broken bridge from a customer with no data.
 */
final class SidebarBuilder
{
    /** @var LoggerInterface */
    private $logger;

    /** @var ActionPermissions|null */
    private $permissions;

    public function __construct(LoggerInterface $logger, ?ActionPermissions $permissions = null)
    {
        $this->logger = $logger;
        $this->permissions = $permissions;
    }

    /**
     * @param Feature[] $features
     * @param object|null $user The agent viewing the page. When given, row
     *        actions they may not run are removed; without permission rules
     *        that means all of them.
     * @return array{state: string, panels: Panel[], summary: ?string, activeTab: ?string}
     */
    public function build(ClientResult $result, array $features, $user = null): array
    {
        if (!$result->ok()) {
            return $this->model('unavailable', []);
        }

        $data = $result->data();
        $sections = is_array($data) && isset($data['sections']) && is_array($data['sections'])
            ? $data['sections']
            : [];
        $missing = is_array($data) && isset($data['missing']) && is_array($data['missing'])
            ? $data['missing']
            : [];

        $panels = [];
        foreach ($features as $feature) {
            try {
                $panel = $this->withMissingRows(
                    $feature,
                    $this->withPermittedActions($feature, $feature->present($sections), $user),
                    $missing
                );
            } catch (\Throwable $e) {
                $this->logger->error('[SpatBridge] feature ' . $feature->key() . ' failed: ' . $e->getMessage());
                continue;
            }
            if ($panel !== null) {
                $panels[] = $panel;
            }
        }

        return $this->model($panels === [] ? 'empty' : 'ok', $panels);
    }

    /**
     * @param object|null $user
     */
    private function withPermittedActions(Feature $feature, ?Panel $panel, $user): ?Panel
    {
        if ($panel === null || $user === null) {
            return $panel;
        }
        if ($this->permissions !== null && $this->permissions->allows($user, $feature->key())) {
            return $panel;
        }

        $rows = [];
        foreach ($panel->rows() as $row) {
            $rows[] = $row->actions() === []
                ? $row
                : new PanelRow($row->title(), $row->lines(), $row->badge(), $row->tone());
        }

        return new Panel($panel->key(), $panel->label(), $rows, $panel->summary(), $panel->attention());
    }

    /**
     * A provider the feature reads that WordPress reports as failed or not
     * enabled gets a muted line on the feature's panel, so the agent doesn't
     * read a missing section as "no data". The panel is created for it when
     * the feature had nothing else to show.
     */
    private function withMissingRows(Feature $feature, ?Panel $panel, array $missing): ?Panel
    {
        $rows = [];
        foreach ($feature->providerKeys() as $provider) {
            $reason = isset($missing[$provider]) && is_string($missing[$provider]) ? $missing[$provider] : '';
            $label = ucfirst(str_replace('_', ' ', $provider));
            if ($reason === 'error') {
                $rows[] = new PanelRow('', [$label . ' ' . __('info unavailable (WordPress error)')]);
            } elseif ($reason === 'not_registered') {
                $rows[] = new PanelRow('', [$label . ' ' . __('info not available — not enabled in WordPress')]);
            }
        }

        if ($rows === []) {
            return $panel;
        }
        if ($panel === null) {
            return new Panel($feature->key(), $feature->label(), $rows);
        }

        return new Panel(
            $panel->key(),
            $panel->label(),
            array_merge($rows, $panel->rows()),
            $panel->summary(),
            $panel->attention()
        );
    }

    /**
     * @param Panel[] $panels
     */
    private function model(string $state, array $panels): array
    {
        $summary = null;
        $activeTab = null;
        foreach ($panels as $panel) {
            if ($summary === null && $panel->summary() !== null) {
                $summary = $panel->summary();
            }
            if ($activeTab === null && $panel->attention()) {
                $activeTab = $panel->key();
            }
        }
        if ($activeTab === null && $panels !== []) {
            $activeTab = $panels[0]->key();
        }

        return ['state' => $state, 'panels' => $panels, 'summary' => $summary, 'activeTab' => $activeTab];
    }
}

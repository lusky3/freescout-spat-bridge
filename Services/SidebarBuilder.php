<?php

namespace Modules\SpatBridge\Services;

use Modules\SpatBridge\Features\Feature;
use Modules\SpatBridge\Features\Panel;
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

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param Feature[] $features
     * @return array{state: string, panels: Panel[], summary: ?string, activeTab: ?string}
     */
    public function build(ClientResult $result, array $features): array
    {
        if (!$result->ok()) {
            return $this->model('unavailable', []);
        }

        $data = $result->data();
        $sections = is_array($data) && isset($data['sections']) && is_array($data['sections'])
            ? $data['sections']
            : [];

        $panels = [];
        foreach ($features as $feature) {
            try {
                $panel = $feature->present($sections);
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

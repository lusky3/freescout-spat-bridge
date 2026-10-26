<?php

namespace Modules\SpatBridge\Features;

/**
 * An action link on a panel row, e.g. "Match to order…". It only describes
 * the action; the preview pop-up decides what running it would do.
 */
final class RowAction
{
    /** @var string */
    private $feature;

    /** @var string */
    private $action;

    /** @var string */
    private $label;

    /** @var array */
    private $params;

    public function __construct(string $feature, string $action, string $label, array $params)
    {
        $this->feature = $feature;
        $this->action = $action;
        $this->label = $label;
        $this->params = $params;
    }

    public function feature(): string
    {
        return $this->feature;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function params(): array
    {
        return $this->params;
    }

    /**
     * The preview URL's query string (without a leading "?").
     */
    public function query(): string
    {
        return http_build_query([
            'feature' => $this->feature,
            'action' => $this->action,
            'params' => $this->params === [] ? '{}' : json_encode($this->params),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}

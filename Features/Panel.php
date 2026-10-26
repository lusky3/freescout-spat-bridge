<?php

namespace Modules\SpatBridge\Features;

final class Panel
{
    /** @var string */
    private $key;

    /** @var string */
    private $label;

    /** @var PanelRow[] */
    private $rows;

    /** @var string|null */
    private $summary;

    /** @var bool */
    private $attention;

    /**
     * @param PanelRow[] $rows
     */
    public function __construct(
        string $key,
        string $label,
        array $rows,
        ?string $summary = null,
        bool $attention = false
    ) {
        $this->key = $key;
        $this->label = $label;
        $this->rows = $rows;
        $this->summary = $summary;
        $this->attention = $attention;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return PanelRow[]
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }

    public function attention(): bool
    {
        return $this->attention;
    }
}

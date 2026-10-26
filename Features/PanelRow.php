<?php

namespace Modules\SpatBridge\Features;

use InvalidArgumentException;

final class PanelRow
{
    private const TONES = ['ok', 'warn', 'bad', 'muted'];

    /** @var string */
    private $title;

    /** @var string[] */
    private $lines;

    /** @var string|null */
    private $badge;

    /** @var string */
    private $tone;

    /**
     * @param string[] $lines
     */
    public function __construct(string $title, array $lines = [], ?string $badge = null, string $tone = 'muted')
    {
        if (!in_array($tone, self::TONES, true)) {
            throw new InvalidArgumentException('Unknown panel row tone: ' . $tone);
        }

        $this->title = $title;
        $this->lines = $lines;
        $this->badge = $badge;
        $this->tone = $tone;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return string[]
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function badge(): ?string
    {
        return $this->badge;
    }

    public function tone(): string
    {
        return $this->tone;
    }
}

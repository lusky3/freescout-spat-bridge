<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\Support\OptionBool;
use PHPUnit\Framework\TestCase;

class OptionBoolTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: bool, 2: bool}>
     */
    public function cases(): array
    {
        return [
            'true' => [true, false, true],
            'string 1' => ['1', false, true],
            'false' => [false, true, false],
            'empty string (stored false)' => ['', true, false],
            'string 0' => ['0', true, false],
            'null uses default true' => [null, true, true],
            'null uses default false' => [null, false, false],
            'garbage uses default true' => ['maybe', true, true],
            'garbage uses default false' => ['maybe', false, false],
        ];
    }

    /**
     * @dataProvider cases
     * @param mixed $value
     */
    public function test_from(
        $value,
        bool $default,
        bool $expected
    ): void {
        $this->assertSame($expected, OptionBool::from($value, $default));
    }
}

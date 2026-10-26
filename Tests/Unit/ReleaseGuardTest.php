<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Scripts\ReleaseGuard;

class ReleaseGuardTest extends TestCase
{
    private const CHANGELOG = <<<'MD'
# Changelog

## [Unreleased]

### Added

- Foo

## [0.2.0] - 2026-11-01

### Added

- Bar
- Baz

## [0.1.0] - 2026-10-01

### Added

- Initial

[0.2.0]: https://example.com/0.2.0
MD;

    public function test_valid_versions(): void
    {
        $this->assertTrue(ReleaseGuard::isValidVersion('1.2.3'));
        $this->assertFalse(ReleaseGuard::isValidVersion('v1.2.3'));
        $this->assertFalse(ReleaseGuard::isValidVersion('1.2'));
        $this->assertFalse(ReleaseGuard::isValidVersion('1.2.3-rc1'));
        $this->assertFalse(ReleaseGuard::isValidVersion("1.2.3\n"));
    }

    public function test_is_greater(): void
    {
        $this->assertTrue(ReleaseGuard::isGreater('0.2.0', '0.1.9'));
        $this->assertTrue(ReleaseGuard::isGreater('0.10.0', '0.9.0'));
        $this->assertTrue(ReleaseGuard::isGreater('0.1.0', null));
        $this->assertFalse(ReleaseGuard::isGreater('0.1.0', '0.1.0'));
        $this->assertFalse(ReleaseGuard::isGreater('0.1.0', '0.2.0'));
    }

    public function test_changelog_section_extracts_body_until_next_heading(): void
    {
        $this->assertSame(
            "### Added\n\n- Bar\n- Baz",
            ReleaseGuard::changelogSection(self::CHANGELOG, '0.2.0')
        );
        $this->assertSame(
            "### Added\n\n- Foo",
            ReleaseGuard::changelogSection(self::CHANGELOG, 'Unreleased')
        );
        // The last section stops at the link-reference footer.
        $this->assertSame(
            "### Added\n\n- Initial",
            ReleaseGuard::changelogSection(self::CHANGELOG, '0.1.0')
        );
        $this->assertNull(ReleaseGuard::changelogSection(self::CHANGELOG, '0.3.0'));
    }

    public function test_unreleased_gained_true_only_for_new_bullet(): void
    {
        $base = "## [Unreleased]\n\n### Added\n\n- Foo\n\n## [0.1.0]\n\n- Old\n";

        $added = str_replace("- Foo\n", "- Foo\n- New thing\n", $base);
        $this->assertTrue(ReleaseGuard::unreleasedGained($base, $added));

        $reworded = str_replace('### Added', '### Changed', $base);
        $this->assertFalse(ReleaseGuard::unreleasedGained($base, $reworded));

        $this->assertFalse(ReleaseGuard::unreleasedGained($base, $base));

        // A bullet added under a released version does not count.
        $released = str_replace("- Old\n", "- Old\n- Late\n", $base);
        $this->assertFalse(ReleaseGuard::unreleasedGained($base, $released));

        $this->assertTrue(ReleaseGuard::unreleasedGained('', $base));
    }

    public function test_tag_errors_lists_every_problem(): void
    {
        $shipped = ['Services/Foo.php'];

        $this->assertSame(
            [],
            ReleaseGuard::tagErrors('0.2.0', '0.2.0', 'v0.1.0', self::CHANGELOG, $shipped)
        );
        $this->assertSame(
            [],
            ReleaseGuard::tagErrors('0.1.0', '0.1.0', null, self::CHANGELOG, [])
        );

        $errors = ReleaseGuard::tagErrors('0.2.0', '0.1.0', 'v0.1.0', self::CHANGELOG, $shipped);
        $this->assertContains(
            'module.json version (0.1.0) does not match the tag version (0.2.0).',
            $errors
        );

        $missing = ReleaseGuard::tagErrors('0.3.0', '0.3.0', 'v0.2.0', self::CHANGELOG, $shipped);
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('## [0.3.0]', $missing[0]);

        $emptyLog = "## [0.3.0]\n\n## [0.2.0]\n- x\n";
        $empty = ReleaseGuard::tagErrors('0.3.0', '0.3.0', 'v0.2.0', $emptyLog, $shipped);
        $this->assertCount(1, $empty);
        $this->assertStringContainsString('## [0.3.0]', $empty[0]);

        $this->assertSame(
            ['Nothing shipped changed since v0.1.0.'],
            ReleaseGuard::tagErrors('0.2.0', '0.2.0', 'v0.1.0', self::CHANGELOG, [])
        );

        $lower = ReleaseGuard::tagErrors('0.1.0', '0.1.0', 'v0.1.0', self::CHANGELOG, $shipped);
        $this->assertCount(1, $lower);
        $this->assertStringContainsString('not greater than v0.1.0', $lower[0]);

        $this->assertNotEmpty(ReleaseGuard::tagErrors('1.2', '1.2', null, self::CHANGELOG, []));
    }
}

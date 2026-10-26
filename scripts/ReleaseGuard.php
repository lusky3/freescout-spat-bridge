<?php

namespace Scripts;

/**
 * Pure release-policy logic: a release cannot ship without a version bump and
 * a changelog entry. No I/O here; scripts/release-guard gathers the inputs
 * from git and files.
 */
final class ReleaseGuard
{
    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+\z/', $version) === 1;
    }

    public static function isGreater(string $new, ?string $previous): bool
    {
        if ($previous === null) {
            return true;
        }

        return version_compare($new, $previous, '>');
    }

    /**
     * Body between "## [$heading]" and the next "## [" heading (or a bare
     * link-reference line such as "[1.0.0]: https://..."), trimmed. Null when
     * the heading is absent.
     */
    public static function changelogSection(string $changelog, string $heading): ?string
    {
        $prefix = '## [' . $heading . ']';
        $body = [];
        $inside = false;

        foreach (preg_split('/\r\n|\n|\r/', $changelog) as $line) {
            if (!$inside) {
                if (strpos($line, $prefix) === 0) {
                    $inside = true;
                }
                continue;
            }
            if (strpos($line, '## [') === 0 || preg_match('/^\[[^\]]+\]:\s/', $line) === 1) {
                break;
            }
            $body[] = $line;
        }

        return $inside ? trim(implode("\n", $body)) : null;
    }

    /**
     * True when the head's [Unreleased] body has at least one "- " line the
     * base's does not.
     */
    public static function unreleasedGained(string $baseChangelog, string $headChangelog): bool
    {
        $base = self::bullets(self::changelogSection($baseChangelog, 'Unreleased'));
        $head = self::bullets(self::changelogSection($headChangelog, 'Unreleased'));

        return count(array_diff($head, $base)) > 0;
    }

    /**
     * @param string[] $shippedChangedSincePreviousTag
     * @return string[] Human-readable errors; empty means OK.
     */
    public static function tagErrors(
        string $tagVersion,
        string $moduleJsonVersion,
        ?string $previousTag,
        string $changelog,
        array $shippedChangedSincePreviousTag
    ): array {
        $errors = [];

        if (!self::isValidVersion($tagVersion)) {
            $errors[] = 'The release tag does not look like vX.Y.Z. Pre-release tags are not supported.';
        }
        if (!self::isValidVersion($moduleJsonVersion)) {
            $errors[] = 'module.json\'s version is not a valid X.Y.Z dotted triple.';
        } elseif ($moduleJsonVersion !== $tagVersion) {
            $errors[] = sprintf(
                'module.json version (%s) does not match the tag version (%s).',
                $moduleJsonVersion,
                $tagVersion
            );
        }

        if ($previousTag !== null) {
            $previousVersion = ltrim($previousTag, 'v');
            if (!self::isGreater($tagVersion, $previousVersion)) {
                $errors[] = sprintf(
                    'Version %s is not greater than %s, the previous release. Bump the version.',
                    $tagVersion,
                    $previousTag
                );
            }
        }

        $section = self::changelogSection($changelog, $tagVersion);
        if ($section === null || $section === '') {
            $errors[] = sprintf(
                'CHANGELOG.md has no non-empty "## [%s]" section. Move the [Unreleased] entries under it.',
                $tagVersion
            );
        }

        if ($previousTag !== null && count($shippedChangedSincePreviousTag) === 0) {
            $errors[] = sprintf('Nothing shipped changed since %s.', $previousTag);
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    private static function bullets(?string $section): array
    {
        if ($section === null) {
            return [];
        }

        $bullets = [];
        foreach (preg_split('/\r\n|\n|\r/', $section) as $line) {
            $line = trim($line);
            if (strpos($line, '- ') === 0) {
                $bullets[] = $line;
            }
        }

        return $bullets;
    }
}

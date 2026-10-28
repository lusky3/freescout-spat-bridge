<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * FreeScout sends `script-src 'self' 'nonce-…'` without 'unsafe-inline', so an
 * inline <script> without the core nonce, or any inline on*= handler, is
 * silently blocked by the browser. Blade views aren't rendered in unit tests,
 * so this guards their source instead.
 */
class ViewCspTest extends TestCase
{
    /**
     * @return string[] One entry per problem found in $source.
     */
    private function problems(string $source): array
    {
        $problems = [];

        preg_match_all('/<script\b[^>]*>/i', $source, $tags);
        foreach ($tags[0] as $tag) {
            if (strpos($tag, '\Helper::cspNonceAttr()') === false) {
                $problems[] = 'script tag without the CSP nonce: ' . $tag;
            }
        }

        preg_match_all('/\son[a-z]+\s*=/i', $source, $handlers);
        foreach ($handlers[0] as $handler) {
            $problems[] = 'inline event handler: ' . trim($handler);
        }

        return $problems;
    }

    public function test_every_view_script_carries_the_nonce_and_no_inline_handlers(): void
    {
        $dir = dirname(__DIR__, 2) . '/Resources/views';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        $checked = 0;
        $problems = [];
        foreach ($files as $file) {
            if (substr($file->getFilename(), -10) !== '.blade.php') {
                continue;
            }
            $checked++;
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            foreach ($this->problems((string) file_get_contents($file->getPathname())) as $problem) {
                $problems[] = $relative . ': ' . $problem;
            }
        }

        $this->assertGreaterThan(0, $checked, 'no Blade views found under ' . $dir);
        $this->assertSame([], $problems);
    }

    public function test_guard_flags_a_bare_script_and_inline_handlers(): void
    {
        $this->assertCount(1, $this->problems('<script>alert(1)</script>'));
        $this->assertCount(1, $this->problems('<script type="text/javascript">x()</script>'));
        $this->assertCount(1, $this->problems('<input type="checkbox" onclick="x()">'));
        $this->assertCount(1, $this->problems('<a href="#" onMouseOver = "x()">a</a>'));
    }

    public function test_guard_accepts_a_nonced_script_and_prose(): void
    {
        $this->assertSame([], $this->problems('<script {!! \Helper::cspNonceAttr() !!}>x()</script>'));
        $this->assertSame([], $this->problems('<p>Shows league info on your WordPress site.</p>'));
    }
}

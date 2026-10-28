<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * When a preview requested from a form (change email, waitlist offer, the
 * e-Transfer picker) comes back as the error partial, actions.js keeps the
 * form and shows the message in the form's own error box. It recognises the
 * partial by a data attribute and reads the message element's text, so the
 * two files must agree. Neither Blade nor the browser runs in unit tests, so
 * this guards the contract in their source.
 */
class ActionErrorPartialTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    public function test_error_partial_is_marked_and_names_its_message(): void
    {
        $view = $this->source('Resources/views/action/error.blade.php');

        $this->assertMatchesRegularExpression(
            '/<div class="spatbridge-action-failed"[^>]*\sdata-spatbridge-error\b/',
            $view
        );
        $this->assertMatchesRegularExpression(
            '/<p class="[^"]*\bspatbridge-action-failed-message\b[^"]*"[^>]*>\{\{ \$message \}\}<\/p>/',
            $view
        );
    }

    public function test_form_previews_route_the_error_partial_to_the_form(): void
    {
        $js = $this->source('Public/js/actions.js');

        $this->assertStringContainsString("'[data-spatbridge-error]'", $js);
        $this->assertStringContainsString("'.spatbridge-action-failed-message'", $js);
        // Parsed inertly, read as text, never written back as HTML.
        $this->assertStringContainsString("document.createElement('template')", $js);
        $this->assertMatchesRegularExpression(
            '/var problem = failureText\(html\);\s*if \(problem !== null\) \{\s*onError\(problem\);\s*return;/',
            $js
        );
    }
}

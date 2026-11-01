<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Markup and script contracts that neither Blade nor the browser exercise in
 * unit tests, guarded in their source: the e-Transfer picker's accessible
 * names and its refusal to offer an order that isn't on hold, and the tab
 * labels that must not wrap.
 */
class PickAndTabsMarkupTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    public function test_the_pick_inputs_have_an_accessible_name(): void
    {
        $view = $this->source('Resources/views/action/etransfer_pick.blade.php');

        $this->assertMatchesRegularExpression('/<label for="spatbridge-pick-order"[^>]*>/', $view);
        $this->assertMatchesRegularExpression('/<input\b[^>]*\bid="spatbridge-pick-order"/', $view);
        $this->assertMatchesRegularExpression('/<label for="spatbridge-pick-search"[^>]*>/', $view);
        $this->assertMatchesRegularExpression('/<input\b[^>]*\bid="spatbridge-pick-search"/', $view);
    }

    public function test_an_order_that_is_not_on_hold_gets_a_disabled_button_and_a_reason(): void
    {
        $js = $this->source('Public/js/actions.js');

        $this->assertStringContainsString('if (mode === \'order\' && !result.on_hold)', $js);
        $this->assertStringContainsString('use.disabled = true;', $js);
        $this->assertStringContainsString('use.title =', $js);
    }

    /**
     * The forms' validation messages come from the views through __(), like
     * every other pop-up string, not from text fixed in the script.
     */
    public function test_form_validation_messages_are_translated_in_the_views(): void
    {
        $js = $this->source('Public/js/actions.js');
        $forms = [
            'Resources/views/action/waitlist_offer_form.blade.php' => 'Enter a number of hours from 1 to 720.',
            'Resources/views/action/change_email_form.blade.php' => 'Enter a valid new email address.',
        ];

        foreach ($forms as $view => $message) {
            $this->assertStringContainsString(
                'data-invalid-text="{{ __(\'' . $message . '\') }}"',
                $this->source($view),
                $view
            );
            $this->assertStringNotContainsString($message, $js);
        }
        $this->assertSame(2, substr_count($js, "form.getAttribute('data-invalid-text')"));
    }

    public function test_tab_labels_do_not_wrap(): void
    {
        $view = $this->source('Resources/views/sidebar/tabbed.blade.php');

        $this->assertMatchesRegularExpression(
            '/<a [^>]*class="spatbridge-tab"[^>]*style="[^"]*white-space:\s*nowrap/',
            $view
        );
    }

    public function test_the_missing_table_hint_points_at_module_install_not_plain_migrate(): void
    {
        $view = $this->source('Resources/views/settings/index.blade.php');

        $this->assertStringContainsString('php artisan freescout:module-install spatbridge', $view);
        $this->assertStringContainsString('as the web server user', $view);
        $this->assertStringNotContainsString('`php artisan migrate`', $view);
    }
}

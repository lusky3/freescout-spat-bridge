<?php

namespace Modules\SpatBridge\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Modules\SpatBridge\Features\FeatureRegistry;
use Modules\SpatBridge\Services\Support\LaravelOptionStore;
use Modules\SpatBridge\Services\Support\OptionBool;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Psr\Log\LoggerInterface;

class SpatBridgeServiceProvider extends ServiceProvider
{
    // @codeCoverageIgnoreStart
    //
    // register()/boot() wire this module into FreeScout's real DI container,
    // Blade view resolver and router. Faking those well enough to unit-test
    // meaningfully isn't worth building; the wiring is integration-tested
    // against a real, running FreeScout instance by the e2e environment.
    public function register()
    {
        $this->app->bind(OptionStoreInterface::class, LaravelOptionStore::class);

        $this->app->singleton(FeatureRegistry::class, function () {
            return new FeatureRegistry();
        });

        $this->app->bind(LoggerInterface::class, function ($app) {
            return $app['log'];
        });

        $this->app->bind(\GuzzleHttp\ClientInterface::class, function () {
            return new Client();
        });
    }

    public function boot()
    {
        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/spatbridge';
        }, \Config::get('view.paths')), [__DIR__ . '/../Resources/views']), 'spatbridge');

        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');

        $this->registerSettingsSection();

        // Feeds Resources/views/settings/index.blade.php so the view stays a
        // pure template.
        \View::composer('spatbridge::settings.index', function ($view) {
            $options = $this->app->make(OptionStoreInterface::class);
            $registry = $this->app->make(FeatureRegistry::class);

            $features = [];
            foreach ($registry->all() as $feature) {
                $features[$feature->key()] = [
                    'key' => $feature->key(),
                    'label' => $feature->label(),
                    'providerKeys' => $feature->providerKeys(),
                    'enabled' => OptionBool::from($options->get('spatbridge.features.' . $feature->key() . '.enabled', true), true),
                ];
            }

            // Saved order first, then anything not yet in it.
            $savedOrder = $options->get('spatbridge.feature_order', []);
            $featureOrder = [];
            foreach (is_array($savedOrder) ? $savedOrder : [] as $key) {
                if (is_string($key) && isset($features[$key])) {
                    $featureOrder[$key] = $features[$key]['label'];
                }
            }
            foreach ($features as $key => $feature) {
                $featureOrder[$key] = $feature['label'];
            }

            $mailboxes = [];
            foreach (\App\Mailbox::all() as $mailbox) {
                $mailboxes[] = ['id' => (int) $mailbox->id, 'name' => (string) $mailbox->name];
            }

            $selected = $options->get('spatbridge.mailboxes', []);

            $view->with('wpBaseUrl', (string) $options->get('spatbridge.wp_base_url', ''));
            $view->with('hasSecret', (string) $options->get('spatbridge.shared_secret', '') !== '');
            $view->with('layoutDefault', $options->get('spatbridge.layout_default', 'stacked') === 'tabbed' ? 'tabbed' : 'stacked');
            $view->with('allowSwitch', OptionBool::from($options->get('spatbridge.allow_agent_switch', true), true));
            $view->with('mailboxes', $mailboxes);
            $view->with('selectedMailboxes', is_array($selected) ? array_map('intval', $selected) : []);
            $view->with('features', array_values($features));
            $view->with('featureOrder', $featureOrder);
        });
    }

    /**
     * Same three-filter shape as ModuleManager: FreeScout's
     * SettingsController@view 404s if settings.section_settings returns an
     * empty array for the section, so we return at least one key.
     */
    protected function registerSettingsSection(): void
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['spatbridge'] = [
                'title' => __('ARL League'),
                'icon' => 'flag',
                'view' => 'spatbridge::settings',
                'order' => 210,
            ];

            return $sections;
        });

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== 'spatbridge') {
                return $settings;
            }

            $options = $this->app->make(OptionStoreInterface::class);
            $settings['spatbridge.wp_base_url'] = $options->get('spatbridge.wp_base_url', '');

            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            if ($section !== 'spatbridge') {
                return $view;
            }

            return 'spatbridge::settings.index';
        }, 20, 2);
    }
    // @codeCoverageIgnoreEnd
}

<?php

namespace Modules\SpatBridge\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Modules\SpatBridge\Services\Support\LaravelOptionStore;
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
    }
    // @codeCoverageIgnoreEnd
}

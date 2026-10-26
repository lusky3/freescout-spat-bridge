<?php

Route::group([
    // Same convention FreeScout core and ModuleManager both use for their
    // own admin-only settings routes.
    'middleware' => ['web', 'auth', 'roles'],
    'roles' => ['admin'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\SpatBridge\\Http\\Controllers',
], function () {
    Route::post('/app-settings/spatbridge/save', [
        'uses' => 'SpatBridgeSettingsController@save',
    ])->name('spatbridge_save_settings');

    Route::post('/app-settings/spatbridge/test', [
        'uses' => 'SpatBridgeSettingsController@test',
    ])->name('spatbridge_test_connection');
});

Route::group([
    // Agent routes: any signed-in user; the controller checks permissions.
    'middleware' => ['web', 'auth'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\SpatBridge\\Http\\Controllers',
], function () {
    Route::get('/spatbridge/action/preview', [
        'uses' => 'ActionController@preview',
    ])->name('spatbridge.action.preview');

    Route::post('/spatbridge/action/run', [
        'uses' => 'ActionController@run',
    ])->name('spatbridge.action.run');

    Route::get('/spatbridge/action/query', [
        'uses' => 'ActionController@query',
    ])->name('spatbridge.action.query');
});

Route::group([
    // Signed events from WordPress: no session, auth or CSRF ('open'); the
    // controller checks the shared-secret signature.
    'middleware' => ['open', 'throttle:120,1'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\SpatBridge\\Http\\Controllers',
], function () {
    Route::post('/spatbridge/events', 'EventsController@receive')->name('spatbridge.events');
});

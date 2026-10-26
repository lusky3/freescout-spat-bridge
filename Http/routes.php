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

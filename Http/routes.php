<?php

Route::group([
    // Same convention FreeScout core and ModuleManager both use for their
    // own admin-only settings routes.
    'middleware' => ['web', 'auth', 'roles'],
    'roles' => ['admin'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\SpatBridge\\Http\\Controllers',
], function () {
    // No routes yet.
});

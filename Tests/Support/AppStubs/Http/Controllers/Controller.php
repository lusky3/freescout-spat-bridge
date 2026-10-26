<?php

namespace App\Http\Controllers;

/**
 * Test-only stand-in for FreeScout's real App\Http\Controllers\Controller
 * base class (itself a thin wrapper around Illuminate\Routing\Controller
 * with the AuthorizesRequests/DispatchesJobs/ValidatesRequests traits).
 *
 * SpatBridgeSettingsController extends this base purely because every
 * FreeScout controller does; it never calls any inherited method
 * ($this->authorize(), $this->middleware(), etc.), so an empty stub is
 * sufficient to let the real controller class autoload and be
 * instantiated outside a fully booted FreeScout/Laravel application. Not
 * used in production -- only present via composer.json's "autoload-dev"
 * App\ mapping, which never ships with the module and is superseded by
 * FreeScout's real class in an actual FreeScout install.
 */
class Controller
{
}

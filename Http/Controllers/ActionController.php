<?php

namespace Modules\SpatBridge\Http\Controllers;

use App\Http\Controllers\Controller;

/**
 * Placeholder so the sidebar's action links have a route to point at; the
 * real preview and run endpoints replace it.
 */
class ActionController extends Controller
{
    public function preview()
    {
        abort(501);
    }
}

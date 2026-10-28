<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/global_helpers.php';

// Modules\SpatBridge\Services\Support\LaravelOptionStore and
// Modules\SpatBridge\Providers\SpatBridgeServiceProvider both call
// FreeScout's real \Option / \Cache facades directly (no constructor
// injection point for either), so the in-memory fakes in Tests/Support
// need to be reachable at those exact global class names.
class_alias(\Tests\Support\FakeOption::class, 'Option');
class_alias(\Tests\Support\FakeCache::class, 'Cache');

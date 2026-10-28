<?php

namespace Tests\Support;

/**
 * A minimal stand-in for the Laravel application container, just enough
 * to satisfy SpatBridgeServiceProvider::cachedLines()'s three
 * `$this->app->make(...)` calls without booting anything. Bindings are
 * supplied up front by the test, so `make()` returns real, hand-built
 * collaborators (a fake option store, a client wired to a mocked
 * Guzzle handler, a real EntryRenderer) rather than mocks that only
 * assert they were called.
 */
class FakeApp
{
    /** @var array<string, mixed> */
    private $bindings = [];

    public function bind(string $abstract, $instance): void
    {
        $this->bindings[$abstract] = $instance;
    }

    /**
     * @return mixed
     */
    public function make(string $abstract)
    {
        if (!array_key_exists($abstract, $this->bindings)) {
            throw new \RuntimeException("FakeApp has no binding for {$abstract}");
        }

        return $this->bindings[$abstract];
    }
}

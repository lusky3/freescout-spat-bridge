<?php

namespace Tests\Support;

/**
 * Stand-in for FreeScout's Customer model, exposing only the one method
 * SpatBridgeServiceProvider::customerEmail() actually calls.
 */
class FakeCustomer
{
    /** @var string|null */
    private $email;

    public function __construct($email)
    {
        $this->email = $email;
    }

    /**
     * @return string|null
     */
    public function getMainEmail()
    {
        return $this->email;
    }
}

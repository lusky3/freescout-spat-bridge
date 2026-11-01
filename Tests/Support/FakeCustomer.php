<?php

namespace Tests\Support;

/**
 * Stand-in for FreeScout's Customer model, exposing only the two methods
 * CustomerEmails::of() calls.
 */
class FakeCustomer
{
    /** @var string|null */
    private $email;

    /** @var string[] */
    private $allEmails;

    /**
     * @param string|null $email     Main email.
     * @param string[]    $allEmails Every email on the record (FreeScout's
     *                               getEmailsAsArray() includes the main one).
     */
    public function __construct($email, array $allEmails = [])
    {
        $this->email = $email;
        $this->allEmails = $allEmails;
    }

    /**
     * @return string|null
     */
    public function getMainEmail()
    {
        return $this->email;
    }

    /**
     * @return string[]
     */
    public function getEmailsAsArray()
    {
        return $this->allEmails;
    }
}

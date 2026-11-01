<?php

namespace Modules\SpatBridge\Services\Support;

/**
 * The addresses a FreeScout customer is looked up by in WordPress: the main
 * email, the conversation's customer address and the customer's other
 * emails. A player, waitlist entry or e-Transfer can sit under any of them.
 *
 * The sidebar lookup, its cache key and the action guard all use this one
 * list, so a cache entry is always cleared under the key it was stored with
 * and the guard accepts exactly the addresses the sidebar showed.
 */
final class CustomerEmails
{
    /** WordPress refuses a lookup of more distinct addresses than this. */
    public const MAX = 5;

    private const CACHE_PREFIX = 'spatbridge.customer.';

    /**
     * Only the customer record and the conversation's customer address are
     * used, never CC, To or thread addresses: those can be other people.
     *
     * @param mixed $customer     FreeScout's Customer (getMainEmail(), getEmailsAsArray()).
     * @param mixed $conversation FreeScout's Conversation (customer_email).
     * @return string[] Trimmed, lower-cased, de-duplicated and plausible
     *         addresses: the main email, then the conversation's customer
     *         address, then the record's other emails; at most MAX.
     */
    public static function of($customer, $conversation): array
    {
        $candidates = [];
        if (is_object($customer) && method_exists($customer, 'getMainEmail')) {
            $candidates[] = $customer->getMainEmail();
        }
        if (is_object($conversation) && isset($conversation->customer_email)) {
            $candidates[] = $conversation->customer_email;
        }
        if (is_object($customer) && method_exists($customer, 'getEmailsAsArray')) {
            foreach ((array) $customer->getEmailsAsArray() as $other) {
                $candidates[] = $other;
            }
        }

        $emails = [];
        foreach ($candidates as $candidate) {
            $candidate = is_scalar($candidate) ? strtolower(trim((string) $candidate)) : '';
            // WordPress refuses the whole lookup when its first address is
            // not one, so nothing implausible is sent.
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$candidate] = $candidate;
            }
        }

        return array_slice(array_values($emails), 0, self::MAX);
    }

    /**
     * The list for a conversation's own customer, as the action endpoints
     * see the ticket.
     *
     * @param mixed $conversation
     * @return string[]
     */
    public static function ofConversation($conversation): array
    {
        $customer = is_object($conversation) ? ($conversation->customer ?? null) : null;

        return self::of($customer, $conversation);
    }

    /**
     * One cache entry per address list. Order counts: WordPress reports the
     * first address as the primary one.
     *
     * @param string[] $emails A list from of() or ofConversation().
     */
    public static function cacheKey(array $emails): string
    {
        return self::CACHE_PREFIX . md5((string) json_encode(array_values($emails)));
    }
}

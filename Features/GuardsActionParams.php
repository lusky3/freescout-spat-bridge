<?php

namespace Modules\SpatBridge\Features;

/**
 * Optional for a Feature: vets an action's params against the ticket's own
 * customer before the preview or the run goes anywhere near WordPress. The
 * params come from the browser, so an action that targets a person must not
 * trust the person it names.
 */
interface GuardsActionParams
{
    /**
     * @param string[] $customerEmails The conversation customer's addresses.
     * @return string|null A message for the agent when the params are refused, else null.
     */
    public function guardParams(string $action, array $params, array $customerEmails): ?string;
}

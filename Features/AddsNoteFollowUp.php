<?php

namespace Modules\SpatBridge\Features;

/**
 * Optional for a Feature: a sentence to add to the internal note after an
 * action has run, e.g. what the agent may need to do next in FreeScout.
 */
interface AddsNoteFollowUp
{
    /**
     * @return string|null Plain text (escaped when the note is built), or null for none.
     */
    public function noteFollowUp(string $action, array $params): ?string;
}

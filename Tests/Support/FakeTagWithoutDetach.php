<?php

namespace Tests\Support;

/**
 * A Tags API without detachByName, as another module version might have.
 */
class FakeTagWithoutDetach
{
    public static function attachByName($name, $conversationId)
    {
        FakeTag::attachByName($name, $conversationId);
    }
}

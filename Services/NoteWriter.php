<?php

namespace Modules\SpatBridge\Services;

/**
 * Adds internal notes to tickets: the outcome of an action, or an event from
 * WordPress. Notes go to agents only; FreeScout never emails them to the
 * customer.
 */
class NoteWriter
{
    /**
     * Note body for a completed action. Everything from WordPress is escaped.
     *
     * @param string[] $changes
     */
    public static function actionBody(string $summary, array $changes): string
    {
        $html = '<p>' . self::escape($summary) . '</p>';
        if ($changes !== []) {
            $html .= '<ul>';
            foreach ($changes as $change) {
                $html .= '<li>' . self::escape((string) $change) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '<p><small>' . self::escape(__('via ARL League')) . '</small></p>';
    }

    /**
     * @param object $conversation FreeScout conversation (with a customer).
     * @param object $user The FreeScout user the note is credited to: the agent
     *                     who ran an action, or the events note user.
     * @param string $html A body built by actionBody() or EventCatalog::noteHtml(),
     *                     never raw input.
     * @throws \RuntimeException When FreeScout refuses the note.
     */
    public function add($conversation, $user, string $html): void
    {
        $created = \App\Thread::createExtended(
            [
                'type' => \App\Thread::TYPE_NOTE,
                'body' => $html,
                'created_by_user_id' => (int) $user->id,
            ],
            $conversation,
            $conversation->customer
        );
        // createExtended() reports bad input by returning false, not throwing.
        if ($created === false) {
            throw new \RuntimeException('FreeScout did not create the note.');
        }
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

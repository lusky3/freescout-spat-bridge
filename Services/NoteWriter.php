<?php

namespace Modules\SpatBridge\Services;

/**
 * Records the outcome of an action as an internal note on the ticket.
 * Notes go to agents only; FreeScout never emails them to the customer.
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
     * @param object $user The agent the note is credited to.
     * @param string $html A body built by actionBody(), never raw input.
     */
    public function add($conversation, $user, string $html): void
    {
        \App\Thread::createExtended(
            [
                'type' => \App\Thread::TYPE_NOTE,
                'body' => $html,
                'created_by_user_id' => (int) $user->id,
            ],
            $conversation,
            $conversation->customer
        );
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

<?php

namespace Tests\Unit;

use App\Thread;
use Modules\SpatBridge\Services\NoteWriter;
use PHPUnit\Framework\TestCase;

class NoteWriterTest extends TestCase
{
    protected function setUp(): void
    {
        Thread::reset();
    }

    public function test_action_body_escapes_summary_and_changes(): void
    {
        $html = NoteWriter::actionBody('Match <script>alert(1)</script> & co', ['Order "#5107": <b>on hold</b>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('Match &lt;script&gt;alert(1)&lt;/script&gt; &amp; co', $html);
        $this->assertStringContainsString('<li>Order &quot;#5107&quot;: &lt;b&gt;on hold&lt;/b&gt;</li>', $html);
        $this->assertStringContainsString('via ARL League', $html);
    }

    public function test_action_body_layout(): void
    {
        $this->assertSame(
            '<p>Match e-Transfer CA8kQ2 to order #5107</p>'
            . '<ul><li>Order #5107: on hold → completed</li><li>e-Transfer recorded</li></ul>'
            . '<p><small>via ARL League</small></p>',
            NoteWriter::actionBody(
                'Match e-Transfer CA8kQ2 to order #5107',
                ['Order #5107: on hold → completed', 'e-Transfer recorded']
            )
        );
    }

    public function test_action_body_without_changes_has_no_list(): void
    {
        $this->assertSame('<p>Done</p><p><small>via ARL League</small></p>', NoteWriter::actionBody('Done', []));
    }

    public function test_add_creates_a_note_thread_by_the_agent(): void
    {
        $customer = new \stdClass();
        $conversation = new \stdClass();
        $conversation->id = 9;
        $conversation->customer = $customer;
        $user = new \stdClass();
        $user->id = 4;

        (new NoteWriter())->add($conversation, $user, '<p>x</p>');

        $this->assertCount(1, Thread::$created);
        [$data, $conv, $cust] = Thread::$created[0];
        $this->assertSame(
            ['type' => Thread::TYPE_NOTE, 'body' => '<p>x</p>', 'created_by_user_id' => 4],
            $data
        );
        $this->assertSame($conversation, $conv);
        $this->assertSame($customer, $cust);
    }
}

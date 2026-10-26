<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\TagService;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTag;
use Tests\Support\FakeTagWithoutDetach;

class TagServiceTest extends TestCase
{
    /** @var object */
    private $conversation;

    protected function setUp(): void
    {
        FakeTag::reset();
        $this->conversation = new \stdClass();
        $this->conversation->id = 11;
    }

    private function service(bool $active, string $class = FakeTag::class): TagService
    {
        return new TagService(function () use ($active) {
            return $active;
        }, $class);
    }

    public function test_the_real_tags_class_is_the_default(): void
    {
        $this->assertSame('Modules\Tags\Entities\Tag', TagService::TAG_CLASS);
    }

    public function test_available_and_working_when_module_class_and_methods_exist(): void
    {
        $tags = $this->service(true);

        $this->assertTrue($tags->available());
        $this->assertTrue($tags->add($this->conversation, 'payment-unmatched'));
        $this->assertTrue($tags->remove($this->conversation, 'waitlist-offered'));
        $this->assertSame([
            ['attach', 'payment-unmatched', 11],
            ['detach', 'waitlist-offered', 11],
        ], FakeTag::$calls);
    }

    public function test_inactive_module_skips(): void
    {
        $tags = $this->service(false);

        $this->assertFalse($tags->available());
        $this->assertFalse($tags->add($this->conversation, 'payment-unmatched'));
        $this->assertFalse($tags->remove($this->conversation, 'payment-unmatched'));
        $this->assertSame([], FakeTag::$calls);
    }

    public function test_missing_class_skips(): void
    {
        $tags = $this->service(true, 'Modules\Tags\Entities\NoSuchTag');

        $this->assertFalse($tags->available());
        $this->assertFalse($tags->add($this->conversation, 'payment-unmatched'));
    }

    public function test_missing_method_skips(): void
    {
        $tags = $this->service(true, FakeTagWithoutDetach::class);

        $this->assertFalse($tags->available());
        $this->assertFalse($tags->add($this->conversation, 'payment-unmatched'));
        $this->assertSame([], FakeTag::$calls);
    }

    public function test_a_failing_tags_call_or_module_check_reports_false(): void
    {
        FakeTag::$failWith = new \RuntimeException('no table');
        $this->assertFalse($this->service(true)->add($this->conversation, 'payment-unmatched'));

        $throwing = new TagService(function () {
            throw new \RuntimeException('modules table missing');
        }, FakeTag::class);
        $this->assertFalse($throwing->available());
    }

    public function test_empty_tag_is_refused(): void
    {
        $this->assertFalse($this->service(true)->add($this->conversation, ' '));
        $this->assertSame([], FakeTag::$calls);
    }
}

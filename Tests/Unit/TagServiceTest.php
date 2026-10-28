<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\TagService;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
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

    /**
     * Tags APIs whose methods exist but don't match the static
     * ($name, $conversationId) signature TagService calls (R-B5).
     *
     * @return array<string, array{0: string}>
     */
    public function oddSignatures(): array
    {
        return [
            'conversation id first' => [get_class(new class {
                public static function attachByName($conversationId, $name)
                {
                    FakeTag::attachByName($name, $conversationId);
                }

                public static function detachByName($conversationId, $name)
                {
                    FakeTag::detachByName($name, $conversationId);
                }
            })],
            'instance methods' => [get_class(new class {
                public function attachByName($name, $conversationId)
                {
                    FakeTag::attachByName($name, $conversationId);
                }

                public function detachByName($name, $conversationId)
                {
                    FakeTag::detachByName($name, $conversationId);
                }
            })],
            'a third required param' => [get_class(new class {
                public static function attachByName($name, $conversationId, $userId)
                {
                    FakeTag::attachByName($name, $conversationId);
                }

                public static function detachByName($name, $conversationId, $userId)
                {
                    FakeTag::detachByName($name, $conversationId);
                }
            })],
            'name only' => [get_class(new class {
                public static function attachByName($name)
                {
                    FakeTag::attachByName($name, 0);
                }

                public static function detachByName($name)
                {
                    FakeTag::detachByName($name, 0);
                }
            })],
        ];
    }

    /**
     * @dataProvider oddSignatures
     */
    public function test_an_unexpected_tags_api_signature_is_skipped_and_logged(string $class): void
    {
        $logger = new TestLogger();
        $tags = new TagService(function () {
            return true;
        }, $class, $logger);

        $this->assertFalse($tags->available());
        $this->assertFalse($tags->add($this->conversation, 'payment-unmatched'));
        $this->assertFalse($tags->remove($this->conversation, 'payment-unmatched'));
        $this->assertSame([], FakeTag::$calls);
        $this->assertTrue($logger->hasWarningThatContains('Tags module'));
    }

    public function test_a_matching_signature_with_an_optional_extra_param_is_used(): void
    {
        $class = get_class(new class {
            public static function attachByName($tagName, $conversation_id, $userId = null)
            {
                FakeTag::attachByName($tagName, $conversation_id);
            }

            public static function detachByName($tagName, $conversation_id, $userId = null)
            {
                FakeTag::detachByName($tagName, $conversation_id);
            }
        });
        $logger = new TestLogger();
        $tags = new TagService(function () {
            return true;
        }, $class, $logger);

        $this->assertTrue($tags->add($this->conversation, 'payment-unmatched'));
        $this->assertSame([['attach', 'payment-unmatched', 11]], FakeTag::$calls);
        $this->assertSame([], $logger->records);
    }

    public function test_the_fake_tag_signature_passes_the_check(): void
    {
        $logger = new TestLogger();
        $tags = new TagService(function () {
            return true;
        }, FakeTag::class, $logger);

        $this->assertTrue($tags->available());
        $this->assertSame([], $logger->records);
    }

    public function test_empty_tag_is_refused(): void
    {
        $this->assertFalse($this->service(true)->add($this->conversation, ' '));
        $this->assertSame([], FakeTag::$calls);
    }
}

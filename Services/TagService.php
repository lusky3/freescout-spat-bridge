<?php

namespace Modules\SpatBridge\Services;

/**
 * Tags conversations through FreeScout's paid Tags module, when it's there.
 *
 * Soft dependency: the module must be active and its Tag entity must have
 * the static attachByName / detachByName methods (the expected API, not
 * verified against a production install). If anything is missing or the
 * call fails, nothing is tagged and the caller records the tag as skipped.
 * Never writes to the Tags tables directly.
 */
class TagService
{
    public const TAG_CLASS = 'Modules\Tags\Entities\Tag';

    /** @var callable(): bool */
    private $moduleActive;

    /** @var string */
    private $tagClass;

    public function __construct(?callable $moduleActive = null, string $tagClass = self::TAG_CLASS)
    {
        $this->moduleActive = $moduleActive ?? [self::class, 'tagsModuleActive'];
        $this->tagClass = $tagClass;
    }

    public function available(): bool
    {
        try {
            if (!call_user_func($this->moduleActive)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return class_exists($this->tagClass)
            && method_exists($this->tagClass, 'attachByName')
            && method_exists($this->tagClass, 'detachByName');
    }

    /**
     * @param object $conversation
     */
    public function add($conversation, string $tag): bool
    {
        return $this->call('attachByName', $conversation, $tag);
    }

    /**
     * @param object $conversation
     */
    public function remove($conversation, string $tag): bool
    {
        return $this->call('detachByName', $conversation, $tag);
    }

    /**
     * @param object $conversation
     */
    private function call(string $method, $conversation, string $tag): bool
    {
        $tag = trim($tag);
        if ($tag === '' || !$this->available()) {
            return false;
        }

        try {
            call_user_func([$this->tagClass, $method], $tag, (int) $conversation->id);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    // @codeCoverageIgnoreStart
    //
    // FreeScout's module repository; the unit tests pass their own check.
    public static function tagsModuleActive(): bool
    {
        return (bool) \Module::isActive('tags');
    }
    // @codeCoverageIgnoreEnd
}

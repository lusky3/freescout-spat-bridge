<?php

namespace Modules\SpatBridge\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tags conversations through FreeScout's paid Tags module, when it's there.
 *
 * Soft dependency: the module must be active and its Tag entity must have
 * public static attachByName / detachByName methods taking ($name,
 * $conversationId) (the expected API, not verified against a production
 * install, R-B5). The signature is checked by reflection before any call:
 * if it differs (another parameter order, instance methods, more required
 * params), nothing is called and a warning is logged. If anything is
 * missing or the call fails, nothing is tagged and the caller records the
 * tag as skipped. Never writes to the Tags tables directly.
 */
class TagService
{
    public const TAG_CLASS = 'Modules\Tags\Entities\Tag';

    /** @var callable(): bool */
    private $moduleActive;

    /** @var string */
    private $tagClass;

    /** @var LoggerInterface */
    private $logger;

    /** @var bool|null The signature check's result, once made. */
    private $signatureOk;

    public function __construct(
        ?callable $moduleActive = null,
        string $tagClass = self::TAG_CLASS,
        ?LoggerInterface $logger = null
    ) {
        $this->moduleActive = $moduleActive ?? [self::class, 'tagsModuleActive'];
        $this->tagClass = $tagClass;
        $this->logger = $logger ?? new NullLogger();
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
            && method_exists($this->tagClass, 'detachByName')
            && $this->signatureMatches();
    }

    /**
     * Both methods are public static, take a name-like first and an id-like
     * second parameter, and need no more than those two.
     */
    private function signatureMatches(): bool
    {
        if ($this->signatureOk !== null) {
            return $this->signatureOk;
        }

        $problem = null;
        foreach (['attachByName', 'detachByName'] as $method) {
            $problem = $this->signatureProblem($method);
            if ($problem !== null) {
                break;
            }
        }
        if ($problem !== null) {
            $this->logger->warning(
                '[SpatBridge] the Tags module\'s API is not the one expected, so tags are skipped: ' . $problem,
                ['class' => $this->tagClass]
            );
        }

        return $this->signatureOk = $problem === null;
    }

    private function signatureProblem(string $method): ?string
    {
        try {
            $reflection = new \ReflectionMethod($this->tagClass, $method);
        } catch (\ReflectionException $e) {
            return $method . ' cannot be inspected';
        }
        if (!$reflection->isPublic() || !$reflection->isStatic()) {
            return $method . ' is not public static';
        }
        $params = $reflection->getParameters();
        if (count($params) < 2 || $reflection->getNumberOfRequiredParameters() > 2) {
            return $method . ' does not take (name, conversation id)';
        }
        if (!preg_match('/name|tag/i', $params[0]->getName()) || !preg_match('/conv|id/i', $params[1]->getName())) {
            return $method . '($' . $params[0]->getName() . ', $' . $params[1]->getName()
                . ') does not take (name, conversation id)';
        }

        return null;
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

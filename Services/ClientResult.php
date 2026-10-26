<?php

namespace Modules\SpatBridge\Services;

/**
 * Immutable outcome of a SPAT request. Never carries an exception: failures
 * are expressed as an error code so callers can fail open.
 */
final class ClientResult
{
    /** @var bool */
    private $ok;
    /** @var int */
    private $status;
    /** @var array|null */
    private $data;
    /** @var string */
    private $errorCode;
    /** @var int */
    private $latencyMs;

    private function __construct(bool $ok, int $status, ?array $data, string $errorCode, int $latencyMs)
    {
        $this->ok = $ok;
        $this->status = $status;
        $this->data = $data;
        $this->errorCode = $errorCode;
        $this->latencyMs = $latencyMs;
    }

    public static function success(int $status, array $data, int $latencyMs): self
    {
        return new self(true, $status, $data, '', $latencyMs);
    }

    public static function failure(string $errorCode, int $status = 0, int $latencyMs = 0): self
    {
        return new self(false, $status, null, $errorCode, $latencyMs);
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    /** HTTP status, or 0 when there was no HTTP response. */
    public function status(): int
    {
        return $this->status;
    }

    public function data(): ?array
    {
        return $this->data;
    }

    /**
     * '' when ok; otherwise one of not_configured, network, timeout, bad_signature, clock_skew,
     * wp_not_configured, rate_limited, http_error, bad_response.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }
}

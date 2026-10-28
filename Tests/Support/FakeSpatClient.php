<?php

namespace Tests\Support;

use GuzzleHttp\Client;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\SpatClient;
use Psr\Log\NullLogger;

/**
 * SpatClient that records action()/query() calls and answers with queued
 * results instead of making HTTP requests. With nothing queued it answers
 * like an unreachable WordPress.
 */
class FakeSpatClient extends SpatClient
{
    /** @var array<int, array{0: string, 1: array}> */
    public $calls = [];

    /** @var ClientResult[] */
    public $results = [];

    public function __construct()
    {
        parent::__construct(new Client(), new FakeOptionStore(), new NullLogger());
    }

    public function action(
        string $feature,
        string $action,
        array $params,
        bool $dryRun,
        string $idempotencyKey,
        string $actorEmail,
        string $actorName
    ): ClientResult {
        $this->calls[] = ['action', [
            'feature' => $feature,
            'action' => $action,
            'params' => $params,
            'dry_run' => $dryRun,
            'key' => $idempotencyKey,
            'actor_email' => $actorEmail,
            'actor_name' => $actorName,
        ]];

        return $this->next();
    }

    public function query(string $feature, string $query, array $params): ClientResult
    {
        $this->calls[] = ['query', ['feature' => $feature, 'query' => $query, 'params' => $params]];

        return $this->next();
    }

    private function next(): ClientResult
    {
        return $this->results === [] ? ClientResult::failure('network') : array_shift($this->results);
    }
}

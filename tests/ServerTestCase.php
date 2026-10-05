<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Hermesi\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/** One real HTTP server per test class, reset before every test. */
abstract class ServerTestCase extends TestCase
{
    protected const KEY = 'hm_sk_test_0123456789';

    protected static TestServer $server;

    /** @var list<float> */
    protected array $waits = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = TestServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
        $this->waits = [];
    }

    /** A client against the test server; retries are off unless asked for, and waits are recorded instead of slept. */
    protected function client(?RetryPolicy $retry = null, ?string $baseUrl = null, float $timeout = 5.0): Hermesi
    {
        return new Hermesi(
            apiKey: self::KEY,
            baseUrl: $baseUrl ?? self::$server->url(),
            timeout: $timeout,
            retry: $retry ?? new RetryPolicy(maxRetries: 0),
            sleep: function (float $seconds): void {
                $this->waits[] = $seconds;
            },
        );
    }

    /** @return array<string, mixed> */
    protected function lastBody(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::$server->last()['body'], true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

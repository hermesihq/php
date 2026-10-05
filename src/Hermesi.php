<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Core;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The client.
 *
 * Thin on purpose. It builds a request, sends it with retries, and turns the answer into a typed result or an exception. It
 * makes no decision about notifications: that is the platform's job, and an SDK that decides is a second implementation of it.
 *
 *     $hermesi = new Hermesi(apiKey: getenv('HERMESI_SECRET_KEY'), baseUrl: 'https://your-hermesi-host');
 *     $hermesi->events->trigger('order.shipped', 'user_8821', ['order_id' => '4821'], idempotencyKey: 'order-4821-shipped');
 *
 * It talks through any PSR-18 client. Pass yours as `$httpClient`, or leave it out: Guzzle or Symfony's HttpClient is used if
 * installed (built here with a timeout and without redirects), and anything else is found by `php-http/discovery`.
 */
final class Hermesi
{
    public readonly Events $events;
    public readonly Subscribers $subscribers;
    public readonly Tokens $tokens;
    private readonly Core $core;

    /**
     * @param string|null                $apiKey   your secret key, `hm_sk_...`, server side only; read from HERMESI_SECRET_KEY if null
     * @param string|null                $baseUrl  where Hermesi runs, for example `https://your-hermesi-host`; read from HERMESI_BASE_URL if null
     * @param float                      $timeout  seconds one attempt may take. Applies to the client this package builds; a client you pass
     *                                             keeps its own timeout, because PSR-18 has no way to set one
     * @param bool                       $simulate send nothing: record the events, see {@see self::simulated()}. No key or URL needed
     * @param callable(float): void|null $sleep    how retries wait, in seconds; for tests
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        ?RetryPolicy $retry = null,
        bool $simulate = false,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?callable $sleep = null,
    ) {
        $this->core = new Core($apiKey, $baseUrl, $timeout, $retry, $simulate, $httpClient, $requestFactory, $streamFactory, $sleep);
        $this->events = new Events($this->core);
        $this->subscribers = new Subscribers($this->core);
        $this->tokens = new Tokens($this->core);
    }

    /** True when nothing is sent. */
    public function isSimulating(): bool
    {
        return $this->core->simulate;
    }

    /**
     * The events recorded by `simulate: true`, oldest first.
     *
     * @return list<SimulatedEvent>
     */
    public function simulated(): array
    {
        return $this->core->simulated();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->core->baseUrl(), 'simulate' => $this->core->simulate];
    }

    public function __serialize(): array
    {
        throw new \LogicException('The Hermesi client holds your secret key and cannot be serialised. Build it where you use it.');
    }
}

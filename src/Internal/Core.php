<?php

declare(strict_types=1);

namespace Hermesi\Internal;

use Hermesi\EventResult;
use Hermesi\RetryPolicy;
use Hermesi\SimulatedEvent;
use Hermesi\Subscriber;
use Hermesi\SubscriberToken;
use Hermesi\Version;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * What the resources share. The secret key is held in a {@see Secret}, so no dump of this object shows it, and this object cannot
 * be serialised; the key is hidden from stack traces on PHP 8.2 and later.
 *
 * @internal
 */
final class Core
{
    public const ENV_KEY = 'HERMESI_SECRET_KEY';
    public const ENV_URL = 'HERMESI_BASE_URL';

    public readonly bool $simulate;

    /** @var list<SimulatedEvent> */
    private array $simulated = [];
    private int $counter = 0;
    private readonly Secret $secret;
    private readonly string $baseUrl;
    private ?Transport $transport = null;

    /**
     * @param callable(float): void|null $sleep
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey,
        ?string $baseUrl,
        private readonly float $timeout,
        ?RetryPolicy $retry,
        bool $simulate,
        private readonly ?ClientInterface $httpClient,
        private readonly ?RequestFactoryInterface $requestFactory,
        private readonly ?StreamFactoryInterface $streamFactory,
        ?callable $sleep,
    ) {
        $this->simulate = $simulate;
        $key = $apiKey ?? self::env(self::ENV_KEY);
        $url = $baseUrl ?? self::env(self::ENV_URL);
        if (null === $key && !$simulate) {
            throw new \InvalidArgumentException(\sprintf('apiKey is required (or set %s). It is your secret key, hm_sk_...', self::ENV_KEY));
        }
        if (null !== $key && !str_starts_with($key, 'hm_sk_')) {
            throw new \InvalidArgumentException('apiKey must be a secret key (hm_sk_...). A public key (hm_pk_...) is for browsers and apps and cannot publish events.');
        }
        if (null === $url && !$simulate) {
            throw new \InvalidArgumentException(\sprintf('baseUrl is required (or set %s), for example https://your-hermesi-host', self::ENV_URL));
        }
        if (null !== $url && 1 !== preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException(\sprintf('baseUrl must start with http:// or https://, got "%s"', $url));
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be above 0');
        }
        $this->secret = new Secret($key ?? '');
        $this->baseUrl = rtrim($url ?? 'http://simulated.invalid', '/');
        $this->retry = $retry ?? new RetryPolicy();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    private readonly RetryPolicy $retry;

    /** @var callable(float): void */
    private readonly mixed $sleep;

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['simulate' => $this->simulate, 'baseUrl' => $this->baseUrl, 'key' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('The Hermesi client holds your secret key and cannot be serialised. Build it where you use it.');
    }

    public function transport(): Transport
    {
        return $this->transport ??= new Transport(
            $this->secret,
            $this->baseUrl,
            $this->retry,
            $this->httpClient ?? HttpClients::client($this->timeout),
            $this->requestFactory ?? HttpClients::requestFactory(),
            $this->streamFactory ?? HttpClients::streamFactory(),
            $this->sleep,
            'hermesi-php/'.Version::VERSION,
        );
    }

    public function mint(string $externalId, string $environmentId, int $ttlSeconds): string
    {
        $key = $this->secret->reveal();

        return SubscriberToken::mint('' === $key ? 'hm_sk_simulated' : $key, $externalId, $environmentId, $ttlSeconds);
    }

    /**
     * @param string|Subscriber|list<string|Subscriber> $recipient
     * @param array<string, mixed>                      $body
     */
    public function simulateEvent(string $name, string|Subscriber|array $recipient, string $key, array $body): EventResult
    {
        ++$this->counter;
        /** @var array<string, mixed> $payload */
        $payload = \is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $this->simulated[] = new SimulatedEvent($name, $recipient, $payload, $key, $body);

        return new EventResult(\sprintf('evt_simulated_%d', $this->counter), 'simulated', [], [], false, $key);
    }

    /** @return list<SimulatedEvent> */
    public function simulated(): array
    {
        return $this->simulated;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}

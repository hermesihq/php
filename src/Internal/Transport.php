<?php

declare(strict_types=1);

namespace Hermesi\Internal;

use Hermesi\Exception\ApiException;
use Hermesi\Exception\ConnectionException;
use Hermesi\RetryPolicy;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Everything the resources share: the key, the retries, the wire. The key never leaves it, and it cannot be dumped or serialised.
 *
 * @internal
 */
final class Transport
{
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param callable(float): void $sleep waits that many seconds
     */
    public function __construct(
        private readonly Secret $secret,
        private readonly string $baseUrl,
        private readonly RetryPolicy $retry,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        callable $sleep,
        private readonly string $userAgent,
    ) {
        $this->sleep = $sleep;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'key' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('The Hermesi client holds your secret key and cannot be serialised. Build it where you use it.');
    }

    /**
     * One call, with retries. Returns a 2xx answer; throws an ApiException or a ConnectionException otherwise.
     */
    public function send(string $method, string $path, string $body, ?string $idempotencyKey): Answer
    {
        $retries = 0;
        while (true) {
            try {
                $answer = $this->attempt($method, $path, $body, $idempotencyKey);
            } catch (ClientExceptionInterface|\RuntimeException $e) {
                // No answer: the connection, a timeout, or the body cut off halfway. `RuntimeException` is what a PSR-7 stream
                // throws when reading fails, and none of our own exceptions reaches here because `attempt` throws none.
                $wait = $this->retry->delay($retries, null);
                if (null === $wait) {
                    throw new ConnectionException(\sprintf('Could not reach Hermesi at %s: %s', $this->baseUrl, $e->getMessage()), 0, $e);
                }
                ++$retries;
                ($this->sleep)($wait);
                continue;
            }
            if ($answer->ok()) {
                return $answer;
            }
            $retryAfter = RetryPolicy::parseRetryAfter($answer->retryAfter);
            if (!RetryPolicy::isRetryableStatus($answer->status)) {
                throw $answer->refusal($retryAfter);
            }
            $wait = $this->retry->delay($retries, $retryAfter);
            if (null === $wait) {
                throw $answer->refusal($retryAfter);
            }
            ++$retries;
            ($this->sleep)($wait);
        }
    }

    private function attempt(string $method, string $path, string $body, ?string $idempotencyKey): Answer
    {
        $request = $this->requests->createRequest($method, $this->baseUrl.$path)
            ->withHeader('Authorization', 'Bearer '.$this->secret->reveal())
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('User-Agent', $this->userAgent)
            ->withBody($this->streams->createStream($body));
        if (null !== $idempotencyKey && '' !== $idempotencyKey) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }
        $response = $this->client->sendRequest($request);

        return $this->read($response);
    }

    /** Read inside the attempt: a connection that drops halfway through the body is a failed attempt. */
    private function read(ResponseInterface $response): Answer
    {
        $retryAfter = $response->getHeaderLine('Retry-After');

        return new Answer(
            $response->getStatusCode(),
            (string) $response->getBody(),
            '' === $retryAfter ? null : $retryAfter,
            'true' === strtolower($response->getHeaderLine('Idempotency-Replayed')),
        );
    }
}

<?php

declare(strict_types=1);

namespace Hermesi;

/**
 * When to try again, and how long to wait.
 *
 * Retried: failures to get an answer (connection, timeout), `429` and `5xx`. Everything else is a refusal that the same request
 * would get again. Every call this SDK makes is safe to repeat: an event carries an idempotency key (generated if the caller gave
 * none, and kept across the retries), so a retry after a lost response cannot send a notification twice, and a preference link
 * is only a link. All times are in seconds.
 */
final class RetryPolicy
{
    /**
     * @param int   $maxRetries    retries after the first attempt; `0` turns retrying off
     * @param float $baseDelay     the first backoff ceiling; it doubles with every retry
     * @param float $maxDelay      no backoff ceiling is ever larger than this
     * @param float $maxRetryAfter the longest `Retry-After` that is waited out; a server asking for more gets the error thrown
     *                             instead, because a request that sleeps for ten minutes is worse than one that fails
     */
    public function __construct(
        public readonly int $maxRetries = 3,
        public readonly float $baseDelay = 0.5,
        public readonly float $maxDelay = 8.0,
        public readonly float $maxRetryAfter = 30.0,
    ) {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be 0 or more');
        }
        foreach (['baseDelay' => $baseDelay, 'maxDelay' => $maxDelay, 'maxRetryAfter' => $maxRetryAfter] as $name => $value) {
            if (!is_finite($value) || $value < 0) {
                throw new \InvalidArgumentException($name.' must be 0 or more');
            }
        }
    }

    /**
     * Seconds to wait before the next attempt, or null to stop and throw.
     *
     * `$retriesSoFar` is how many retries have already been made. A `Retry-After` from the server is honoured exactly (it is the
     * server's own estimate, and adding jitter would only make it wait longer than it asked); otherwise the wait is exponential
     * with equal jitter, so that a fleet of callers that failed together does not retry together and no retry is near-instant.
     *
     * @param (callable(): float)|null $random a draw in [0, 1); for tests
     */
    public function delay(int $retriesSoFar, ?float $retryAfter, ?callable $random = null): ?float
    {
        if ($retriesSoFar >= $this->maxRetries) {
            return null;
        }
        if (null !== $retryAfter) {
            return $retryAfter <= $this->maxRetryAfter ? $retryAfter : null;
        }
        $draw = null === $random ? mt_rand() / (mt_getrandmax() + 1) : $random();
        $ceiling = min($this->maxDelay, $this->baseDelay * (2 ** $retriesSoFar));

        return $ceiling / 2 + $draw * $ceiling / 2;
    }

    /**
     * `Retry-After` in seconds. Hermesi sends seconds and never an HTTP date; anything else is ignored. Read with a pattern rather
     * than a cast, which turns an empty header into `0` and would have the client retry at once.
     */
    public static function parseRetryAfter(?string $value): ?float
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return 1 === preg_match('/^\d+(?:\.\d+)?$/', $trimmed) ? (float) $trimmed : null;
    }

    public static function isRetryableStatus(int $status): bool
    {
        return 429 === $status || $status >= 500;
    }
}

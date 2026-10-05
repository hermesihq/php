<?php

declare(strict_types=1);

namespace Hermesi\Internal;

use Hermesi\Exception\ApiException;

/**
 * What came back, read once: the status, the body text and the two headers this package uses.
 *
 * @internal
 */
final class Answer
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $retryAfter,
        public readonly bool $replayed,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** The body as JSON, or null when it is not. */
    public function json(): mixed
    {
        try {
            return json_decode($this->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    public function refusal(?float $retryAfter): ApiException
    {
        return ApiException::fromResponse($this->status, $this->json(), $retryAfter);
    }
}

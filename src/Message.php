<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** A message and how far it got. */
final class Message
{
    /**
     * @param string $status  `queued`, `routing`, `sent`, `delivered`, `opened`, `clicked`, or a refusal: `failed`, `bounced`, `suppressed`, `skipped`, `cancelled`
     * @param bool   $isFinal true once nothing more will happen to this message
     */
    public function __construct(
        public readonly string $id,
        public readonly string $channel,
        public readonly string $status,
        public readonly ?string $stepKey = null,
        public readonly ?string $provider = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $terminalAt = null,
        public readonly bool $isFinal = false,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        $terminalAt = Wire::nullableText($body['terminal_at'] ?? null);

        return new self(
            Wire::text($body['id'] ?? ''),
            Wire::text($body['channel'] ?? ''),
            Wire::text($body['status'] ?? ''),
            Wire::nullableText($body['step_key'] ?? null),
            Wire::nullableText($body['provider'] ?? null),
            Wire::nullableText($body['failure_code'] ?? null),
            Wire::nullableText($body['failure_message'] ?? null),
            Wire::nullableText($body['created_at'] ?? null),
            $terminalAt,
            null !== $terminalAt,
        );
    }
}

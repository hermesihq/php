<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** A destination registered for a subscriber: a device token, a chat id, a Web Push endpoint. */
final class ChannelIdentity
{
    /**
     * @param string               $state    `active`, or `invalid` / `unsubscribed` once a provider or the person said so
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $identifier,
        public readonly string $state = 'active',
        public readonly ?string $stateReason = null,
        public readonly array $metadata = [],
        public readonly ?string $verifiedAt = null,
        public readonly ?string $lastUsedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        return new self(
            Wire::text($body['channel'] ?? ''),
            Wire::text($body['identifier'] ?? ''),
            \is_string($body['state'] ?? null) ? $body['state'] : 'active',
            Wire::nullableText($body['state_reason'] ?? null),
            Wire::map($body['metadata'] ?? null),
            Wire::nullableText($body['verified_at'] ?? null),
            Wire::nullableText($body['last_used_at'] ?? null),
        );
    }
}

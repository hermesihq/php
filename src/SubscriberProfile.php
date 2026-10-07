<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** What Hermesi holds about a subscriber. ({@see Subscriber} is the shape you *send* inline with an event.) */
final class SubscriberProfile
{
    /**
     * @param array<string, mixed>  $data
     * @param list<ChannelIdentity> $channels
     */
    public function __construct(
        public readonly string $id,
        public readonly string $externalId,
        public readonly ?string $email = null,
        public readonly ?string $phoneE164 = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $locale = null,
        public readonly ?string $timezone = null,
        public readonly ?string $avatarUrl = null,
        public readonly array $data = [],
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly array $channels = [],
        public readonly Preferences $preferences = new Preferences(),
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        return new self(
            Wire::text($body['id'] ?? ''),
            Wire::text($body['external_id'] ?? ''),
            Wire::nullableText($body['email'] ?? null),
            Wire::nullableText($body['phone_e164'] ?? null),
            Wire::nullableText($body['first_name'] ?? null),
            Wire::nullableText($body['last_name'] ?? null),
            Wire::nullableText($body['locale'] ?? null),
            Wire::nullableText($body['timezone'] ?? null),
            Wire::nullableText($body['avatar_url'] ?? null),
            Wire::map($body['data'] ?? null),
            Wire::nullableText($body['created_at'] ?? null),
            Wire::nullableText($body['updated_at'] ?? null),
            array_map(ChannelIdentity::fromWire(...), Wire::records($body['channels'] ?? null)),
            Preferences::fromWire(Wire::map($body['preferences'] ?? null)),
        );
    }
}

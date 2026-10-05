<?php

declare(strict_types=1);

namespace Hermesi;

/** A recipient described inline. Sent with an event, it creates or updates the subscriber on the fly. */
final class Subscriber
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $email = null,
        public readonly ?string $phoneE164 = null,
        public readonly ?string $name = null,
        public readonly ?string $locale = null,
        public readonly ?array $data = null,
    ) {
    }
}

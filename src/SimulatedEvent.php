<?php

declare(strict_types=1);

namespace Hermesi;

/** What `simulate: true` records instead of sending, so a test can assert on it. */
final class SimulatedEvent
{
    /**
     * @param string|Subscriber|list<string|Subscriber> $recipient as you gave it
     * @param array<string, mixed>                      $payload   as it would have been sent: dates already text
     * @param array<string, mixed>                      $body      exactly the JSON object that would have been posted
     */
    public function __construct(
        public readonly string $name,
        public readonly string|Subscriber|array $recipient,
        public readonly array $payload,
        public readonly string $idempotencyKey,
        public readonly array $body,
    ) {
    }
}

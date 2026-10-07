<?php

declare(strict_types=1);

namespace Hermesi;

/** What `simulate: true` records for every call that is not an event, so a test can assert on it. */
final class SimulatedCall
{
    /**
     * @param array<mixed>|null $body exactly the JSON body that would have been sent, if there is one
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?array $body,
        public readonly ?string $idempotencyKey,
    ) {
    }
}

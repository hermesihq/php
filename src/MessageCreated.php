<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** One message a direct send created. */
final class MessageCreated
{
    /**
     * @param string $status `queued` when it will be sent; `skipped` or `suppressed` when preferences or the suppression list refused it
     */
    public function __construct(
        public readonly string $id,
        public readonly string $channel,
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        return new self(
            Wire::text($body['id'] ?? ''),
            Wire::text($body['channel'] ?? ''),
            Wire::text($body['status'] ?? ''),
            Wire::nullableText($body['reason'] ?? null),
        );
    }
}

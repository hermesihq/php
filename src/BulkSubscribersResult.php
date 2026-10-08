<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** The answer to `$hermesi->subscribers->bulk()`: one entry per row you sent, in the same order. */
final class BulkSubscribersResult
{
    /**
     * @param list<BulkSubscriberResult> $subscribers
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly array $subscribers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        return new self(
            \is_int($body['created'] ?? null) ? $body['created'] : 0,
            \is_int($body['updated'] ?? null) ? $body['updated'] : 0,
            array_map(BulkSubscriberResult::fromWire(...), Wire::records($body['subscribers'] ?? null)),
        );
    }
}

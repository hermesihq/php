<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** One row of a bulk import, as it came out. */
final class BulkSubscriberResult
{
    /**
     * @param string $status `created`: a subscriber that did not exist (or had been deleted, which comes back empty). `updated`: one that did.
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $id,
        public readonly string $status,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        return new self(Wire::text($body['external_id'] ?? ''), Wire::text($body['id'] ?? ''), Wire::text($body['status'] ?? ''));
    }
}

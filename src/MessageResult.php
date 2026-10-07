<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** The answer to `$hermesi->messages->send()`. `202`: recorded and queued, nothing is delivered yet. */
final class MessageResult
{
    /**
     * @param list<MessageCreated> $messages       one per destination: a push to three devices is three messages
     * @param bool                 $replayed       true when the server recognised the idempotency key and returned the original answer instead of sending again
     * @param string               $idempotencyKey the key the request went out with
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $status,
        public readonly array $messages = [],
        public readonly bool $replayed = false,
        public readonly string $idempotencyKey = '',
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body, bool $replayed, string $idempotencyKey): self
    {
        return new self(
            Wire::text($body['message_id'] ?? ''),
            Wire::text($body['status'] ?? ''),
            array_map(MessageCreated::fromWire(...), Wire::records($body['messages'] ?? null)),
            $replayed,
            $idempotencyKey,
        );
    }
}

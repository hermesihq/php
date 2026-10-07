<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** An event and everything it caused: `$hermesi->events->get($eventId)`. */
final class EventRun
{
    /**
     * @param string                    $status        `processed`, `no_workflow` (no active workflow matched), or `invalid` (a strict payload schema refused it)
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $actor
     * @param array<string, mixed>|null $error
     * @param list<RunNotification>     $notifications one per recipient
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $name,
        public readonly string $status,
        public readonly array $payload = [],
        public readonly ?array $actor = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?array $error = null,
        public readonly ?string $receivedAt = null,
        public readonly ?string $processedAt = null,
        public readonly array $notifications = [],
    ) {
    }

    /**
     * Every message of every notification, flattened.
     *
     * @return list<Message>
     */
    public function messages(): array
    {
        $all = [];
        foreach ($this->notifications as $notification) {
            foreach ($notification->messages as $message) {
                $all[] = $message;
            }
        }

        return $all;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        return new self(
            Wire::text($body['event_id'] ?? ''),
            Wire::text($body['name'] ?? ''),
            Wire::text($body['status'] ?? ''),
            Wire::map($body['payload'] ?? null),
            Wire::nullableMap($body['actor'] ?? null),
            Wire::nullableText($body['idempotency_key'] ?? null),
            Wire::nullableMap($body['error'] ?? null),
            Wire::nullableText($body['received_at'] ?? null),
            Wire::nullableText($body['processed_at'] ?? null),
            array_map(RunNotification::fromWire(...), Wire::records($body['notifications'] ?? null)),
        );
    }
}

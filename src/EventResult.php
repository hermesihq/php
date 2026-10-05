<?php

declare(strict_types=1);

namespace Hermesi;

/** The answer to publishing an event. `202`: the event is recorded and queued, nothing is delivered yet. */
final class EventResult
{
    /**
     * @param list<NotificationSummary> $notifications
     * @param list<string>              $warnings
     * @param bool                      $replayed       true when the server recognised the idempotency key and returned the original answer
     * @param string                    $idempotencyKey the key the request went out with, generated if you gave none; quote it to find the event again
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $status = 'accepted',
        public readonly array $notifications = [],
        public readonly array $warnings = [],
        public readonly bool $replayed = false,
        public readonly string $idempotencyKey = '',
    ) {
    }

    /**
     * @param array<mixed> $body
     */
    public static function fromWire(array $body, bool $replayed, string $idempotencyKey): self
    {
        $notifications = [];
        if (isset($body['notifications']) && \is_array($body['notifications'])) {
            foreach ($body['notifications'] as $item) {
                if (\is_array($item)) {
                    $notifications[] = new NotificationSummary(
                        self::text($item['id'] ?? ''),
                        self::text($item['subscriber_id'] ?? ''),
                        self::text($item['workflow'] ?? ''),
                    );
                }
            }
        }
        $warnings = [];
        if (isset($body['warnings']) && \is_array($body['warnings'])) {
            foreach ($body['warnings'] as $warning) {
                $warnings[] = self::text($warning);
            }
        }

        return new self(
            self::text($body['event_id'] ?? ''),
            isset($body['status']) && \is_string($body['status']) ? $body['status'] : 'accepted',
            $notifications,
            $warnings,
            $replayed,
            $idempotencyKey,
        );
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}

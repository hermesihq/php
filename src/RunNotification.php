<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** One run of one workflow for one recipient, with the messages it produced. */
final class RunNotification
{
    /**
     * @param string        $resumeAt while `waiting`: when the run goes on (a delay, a schedule, or a fallback window)
     * @param list<Message> $messages
     */
    public function __construct(
        public readonly string $id,
        public readonly string $subscriberId,
        public readonly string $externalId,
        public readonly ?string $workflow,
        public readonly ?int $workflowVersion,
        public readonly string $status,
        public readonly ?string $createdAt = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $completedAt = null,
        public readonly ?string $resumeAt = null,
        public readonly array $messages = [],
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        return new self(
            Wire::text($body['id'] ?? ''),
            Wire::text($body['subscriber_id'] ?? ''),
            Wire::text($body['external_id'] ?? ''),
            Wire::nullableText($body['workflow'] ?? null),
            isset($body['workflow_version']) && \is_int($body['workflow_version']) ? $body['workflow_version'] : null,
            Wire::text($body['status'] ?? ''),
            Wire::nullableText($body['created_at'] ?? null),
            Wire::nullableText($body['started_at'] ?? null),
            Wire::nullableText($body['completed_at'] ?? null),
            Wire::nullableText($body['resume_at'] ?? null),
            array_map(Message::fromWire(...), Wire::records($body['messages'] ?? null)),
        );
    }
}

<?php

declare(strict_types=1);

namespace Hermesi;

/** One notification an event produced: which subscriber, through which workflow. */
final class NotificationSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $subscriberId,
        public readonly string $workflow,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hermesi;

/** Who did the thing the event reports, for templates that say "Ada commented". */
final class Actor
{
    public function __construct(
        public readonly ?string $externalId = null,
        public readonly ?string $name = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** One thing wrong with a request, as the API describes it. */
final class ErrorDetail
{
    public function __construct(
        public readonly ?string $field,
        public readonly ?string $issue,
    ) {
    }
}

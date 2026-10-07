<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** 409: an idempotency key that was already used with a different request body (`idempotency_key_reused`). */
final class ConflictException extends ApiException
{
}

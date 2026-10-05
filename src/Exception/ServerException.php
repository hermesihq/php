<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** 5xx: Hermesi failed. For an event, retrying with the same idempotency key is safe. */
final class ServerException extends ApiException
{
}

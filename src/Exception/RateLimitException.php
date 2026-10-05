<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** 429: the environment is publishing faster than Hermesi accepts. Nothing was recorded. */
final class RateLimitException extends ApiException
{
}

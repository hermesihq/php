<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** Hermesi could not be reached: DNS, a refused or dropped connection, or a timeout, after the retries were used up. */
final class ConnectionException extends HermesiException
{
}

<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/**
 * Base class for everything this package throws on purpose when talking to Hermesi.
 *
 * A mistake in how you call it (a public key, a missing name, a payload that cannot be sent) is an
 * \InvalidArgumentException instead, and is not a HermesiException: it is a bug to fix, not a failure to handle.
 */
class HermesiException extends \RuntimeException
{
}

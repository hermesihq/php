<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** A read was asked of a client in `simulate: true` mode. Nothing was sent, so there is nothing to read back. */
final class SimulationException extends HermesiException
{
}

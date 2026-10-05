<?php

declare(strict_types=1);

namespace Hermesi\Tests\Support;

/** An enum without a value: the kind that cannot be sent. */
enum Plain
{
    case One;
}

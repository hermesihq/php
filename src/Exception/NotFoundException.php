<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** 404: for an event, a `recipient` that is not a subscriber in this environment. */
final class NotFoundException extends ApiException
{
}

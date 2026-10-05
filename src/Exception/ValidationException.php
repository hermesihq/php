<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** 400 or 422: the request was refused as malformed; `details` names the fields. */
final class ValidationException extends ApiException
{
}

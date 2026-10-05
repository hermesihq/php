<?php

declare(strict_types=1);

namespace Hermesi\Exception;

/** 401: the API key is missing, malformed, invalid or revoked. */
final class AuthenticationException extends ApiException
{
}

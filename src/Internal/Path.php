<?php

declare(strict_types=1);

namespace Hermesi\Internal;

/** @internal */
final class Path
{
    /**
     * A value as one path segment. `.` and `..` are refused: a URL parser resolves them, even percent-encoded, which would aim a
     * request carrying the secret key at another endpoint.
     */
    public static function segment(string $value, string $name = 'externalId'): string
    {
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('%s is required', $name));
        }
        if ('.' === $value || '..' === $value) {
            throw new \InvalidArgumentException(\sprintf('%s cannot be "%s"', $name, $value));
        }

        return rawurlencode($value);
    }
}

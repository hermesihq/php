<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Exception\ApiException;
use Hermesi\Internal\Core;

final class Subscribers
{
    /** @internal */
    public function __construct(private readonly Core $core)
    {
    }

    /** A link to the hosted preference page for one subscriber. It needs no login and works for about a year. */
    public function preferenceLink(string $externalId): PreferenceLink
    {
        $segment = self::segment($externalId);
        if ($this->core->simulate) {
            return new PreferenceLink('https://simulated.invalid/preferences/'.$segment);
        }
        $answer = $this->core->transport()->send('POST', '/v1/subscribers/'.$segment.'/preference-link', '{}', null);
        $body = $answer->json();
        if (!\is_array($body) || !isset($body['url']) || !\is_string($body['url'])) {
            throw ApiException::fromResponse($answer->status, null, null);
        }

        return new PreferenceLink($body['url']);
    }

    /**
     * A subscriber's id as one path segment. `.` and `..` are refused: a URL parser resolves them, even percent-encoded, which
     * would aim a request carrying the secret key at another endpoint.
     */
    private static function segment(string $externalId): string
    {
        if ('' === $externalId) {
            throw new \InvalidArgumentException('externalId is required');
        }
        if ('.' === $externalId || '..' === $externalId) {
            throw new \InvalidArgumentException(\sprintf('externalId cannot be "%s"', $externalId));
        }

        return rawurlencode($externalId);
    }
}

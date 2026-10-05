<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Core;

/** Subscriber tokens, minted locally: no request is made. */
final class Tokens
{
    /** @internal */
    public function __construct(private readonly Core $core)
    {
    }

    /**
     * A token that lets a browser or an app act as `$externalId`. Valid for at most an hour: mint a fresh one when asked.
     *
     * `$environmentId` is the `env_...` id of the environment the secret key belongs to (shown in the dashboard).
     */
    public function mint(string $externalId, string $environmentId, int $ttlSeconds = SubscriberToken::MAX_TTL_SECONDS): string
    {
        return $this->core->mint($externalId, $environmentId, $ttlSeconds);
    }
}

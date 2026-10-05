<?php

declare(strict_types=1);

namespace Hermesi;

/**
 * Subscriber tokens: what lets a browser or a phone talk to Hermesi's client API as one subscriber.
 *
 * Minted on your server with your **secret** key, and handed to the app, which sends it with its public key. The format is the
 * one Hermesi's integration guide gives: `base64url(payload) . "." . base64url(hmac_sha256(keyHash, base64url(payload)))` where
 * the HMAC key is the SHA-256 hex digest of the raw secret key, **not the key itself**. Hermesi stores only that digest, never
 * your key, which is what lets it verify a signature without ever having the key. Signing with the raw key is the mistake that
 * makes every token be rejected.
 */
final class SubscriberToken
{
    /** The longest a token may live, in seconds. The API refuses a later expiry. */
    public const MAX_TTL_SECONDS = 3600;

    /**
     * A token for `$externalId` in `$environmentId`, valid for `$ttlSeconds` (at most an hour).
     *
     * `$environmentId` is the `env_...` id of the environment the key belongs to, shown in the dashboard: the key itself does not
     * carry it. `$now` is a unix timestamp, for tests.
     */
    public static function mint(
        #[\SensitiveParameter]
        string $secretKey,
        string $externalId,
        string $environmentId,
        int $ttlSeconds = self::MAX_TTL_SECONDS,
        ?int $now = null,
    ): string {
        if (!str_starts_with($secretKey, 'hm_sk_')) {
            throw new \InvalidArgumentException('The key must be a secret key (hm_sk_...), not a public key');
        }
        if ('' === $externalId) {
            throw new \InvalidArgumentException('externalId is required');
        }
        if ('' === $environmentId) {
            throw new \InvalidArgumentException('environmentId is required');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException(\sprintf('ttlSeconds must be between 1 and %d', self::MAX_TTL_SECONDS));
        }
        $payload = self::base64Url(json_encode(
            ['sub' => $externalId, 'env' => $environmentId, 'exp' => ($now ?? time()) + $ttlSeconds],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        ));
        $keyHash = hash('sha256', $secretKey);

        return $payload.'.'.self::base64Url(hash_hmac('sha256', $payload, $keyHash, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

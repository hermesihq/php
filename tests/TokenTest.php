<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Hermesi;
use Hermesi\SubscriberToken;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    private const KEY = 'hm_sk_test_0123456789';
    private const NOW = 1_760_000_000;

    /** @return array{payload: string, signature: string, claims: array<string, mixed>} */
    private static function parts(string $token): array
    {
        [$payload, $signature] = explode('.', $token);
        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);

        return ['payload' => $payload, 'signature' => $signature, 'claims' => $claims];
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function testNamesTheSubscriberTheEnvironmentAndAnExpiry(): void
    {
        $parts = self::parts(SubscriberToken::mint(self::KEY, 'user_8821', 'env_01ABC', 600, self::NOW));

        self::assertSame(['sub' => 'user_8821', 'env' => 'env_01ABC', 'exp' => 1_760_000_600], $parts['claims']);
    }

    public function testIsSignedWithAnHmacKeyedByTheSha256HexDigestOfTheSecretKeyNotByTheKey(): void
    {
        $parts = self::parts(SubscriberToken::mint(self::KEY, 'user_8821', 'env_01ABC', 3600, self::NOW));

        $keyHash = hash('sha256', self::KEY);
        self::assertSame(self::b64(hash_hmac('sha256', $parts['payload'], $keyHash, true)), $parts['signature']);
        self::assertNotSame(self::b64(hash_hmac('sha256', $parts['payload'], self::KEY, true)), $parts['signature']);
    }

    public function testMatchesThePythonAndNodeSdksByteForByteSoATokenFromAnyOfThemIsOneTheServerAccepts(): void
    {
        // Produced by hermesi-python 0.1.0 with the same inputs; @hermesihq/node asserts the same two strings.
        self::assertSame(
            'eyJzdWIiOiJ1c2VyXzg4MjEiLCJlbnYiOiJlbnZfMDFBQkMiLCJleHAiOjE3NjAwMDM2MDB9.Jh3aTNvGnI9Oig2wXFqwhhqlDg2OG18OU16iEAW6lA0',
            SubscriberToken::mint(self::KEY, 'user_8821', 'env_01ABC', 3600, self::NOW),
        );
        self::assertSame(
            'eyJzdWIiOiJ1c2VyXzg4MjEiLCJlbnYiOiJlbnZfMDFBQkMiLCJleHAiOjE3NjAwMDAwNjB9.1tSXSD-WfJ7KbF0c-JoxbJJiGvUIUwR7Dqwi_Q-LKu8',
            SubscriberToken::mint(self::KEY, 'user_8821', 'env_01ABC', 60, self::NOW),
        );
    }

    public function testIsUrlSafeAndUnpaddedWhateverTheId(): void
    {
        foreach (['a', 'ab', 'abc', 'user_1', '???>>>', '~~~~~', 'é', 'Ünï¢ødé', '日本語', 'team/42', str_repeat('x', 50)] as $id) {
            $parts = self::parts(SubscriberToken::mint(self::KEY, $id, 'env_1', 3600, self::NOW));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $parts['payload'], $id);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $parts['signature'], $id);
            self::assertSame($id, $parts['claims']['sub'], $id);
        }
    }

    public function testGivesTheSameTokenForTheSameInputsAndADifferentOneForADifferentSecret(): void
    {
        $a = SubscriberToken::mint(self::KEY, 'u', 'e', 3600, self::NOW);

        self::assertSame($a, SubscriberToken::mint(self::KEY, 'u', 'e', 3600, self::NOW));
        self::assertNotSame($a, SubscriberToken::mint('hm_sk_other_key', 'u', 'e', 3600, self::NOW));
    }

    public function testDefaultsToAnHourAndNeverAllowsMore(): void
    {
        $before = time();
        $claims = self::parts(SubscriberToken::mint(self::KEY, 'u', 'e'))['claims'];

        self::assertGreaterThanOrEqual($before + 3600, $claims['exp']);
        self::assertLessThanOrEqual(time() + 3600, $claims['exp']);

        $this->expectException(\InvalidArgumentException::class);
        SubscriberToken::mint(self::KEY, 'u', 'e', 3601);
    }

    public function testRefusesWhatCannotMakeAToken(): void
    {
        foreach ([
            static fn () => SubscriberToken::mint('hm_pk_public', 'u', 'e'),
            static fn () => SubscriberToken::mint(self::KEY, '', 'e'),
            static fn () => SubscriberToken::mint(self::KEY, 'u', ''),
            static fn () => SubscriberToken::mint(self::KEY, 'u', 'e', 0),
            static fn () => SubscriberToken::mint(self::KEY, 'u', 'e', -1),
        ] as $make) {
            try {
                $make();
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIsMintedByTheClientWithItsOwnKeyAndMakesNoRequest(): void
    {
        $hermesi = new Hermesi(apiKey: self::KEY, baseUrl: 'http://127.0.0.1:9');

        $token = $hermesi->tokens->mint('user_8821', environmentId: 'env_01ABC');

        $parts = self::parts($token);
        self::assertSame(self::b64(hash_hmac('sha256', $parts['payload'], hash('sha256', self::KEY), true)), $parts['signature']);
        self::assertSame('user_8821', $parts['claims']['sub']);
    }
}

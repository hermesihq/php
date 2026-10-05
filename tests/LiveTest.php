<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Exception\AuthenticationException;
use Hermesi\Exception\ConnectionException;
use Hermesi\Exception\NotFoundException;
use Hermesi\Exception\ValidationException;
use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Hermesi\Subscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The SDK against a real Hermesi, not a fake of one.
 *
 * Skipped unless the variables below are set. These exist because tests written against a fake of the server prove the client
 * and not the contract: a client can pass hundreds of them and still disagree with the server about a path, a header or a
 * format. Run them against a development instance of Hermesi (never production: they publish events):
 *
 *     HERMESI_LIVE_URL=http://localhost:8010 HERMESI_LIVE_SECRET_KEY=hm_sk_... HERMESI_LIVE_PUBLIC_KEY=hm_pk_... \
 *     HERMESI_LIVE_ENVIRONMENT_ID=env_... HERMESI_LIVE_SUBSCRIBER=user_1 vendor/bin/phpunit --group live
 *
 * The subscriber must already exist in that environment.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    private string $url;
    private string $secret;
    private string $public;
    private string $environment;
    private string $subscriber;

    protected function setUp(): void
    {
        $get = static fn (string $name): string => (string) getenv($name);
        $this->url = $get('HERMESI_LIVE_URL');
        $this->secret = $get('HERMESI_LIVE_SECRET_KEY');
        $this->public = $get('HERMESI_LIVE_PUBLIC_KEY');
        $this->environment = $get('HERMESI_LIVE_ENVIRONMENT_ID');
        $this->subscriber = $get('HERMESI_LIVE_SUBSCRIBER');
        if ('' === $this->url || '' === $this->secret || '' === $this->public || '' === $this->environment || '' === $this->subscriber) {
            self::markTestSkipped('set HERMESI_LIVE_URL, _SECRET_KEY, _PUBLIC_KEY, _ENVIRONMENT_ID and _SUBSCRIBER to run against a real Hermesi');
        }
    }

    private function live(): Hermesi
    {
        return new Hermesi(apiKey: $this->secret, baseUrl: $this->url, retry: new RetryPolicy(maxRetries: 0));
    }

    private static function unique(): string
    {
        return 'live_'.substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function testPublishesAnEventAndTheServerAcceptsIt(): void
    {
        $result = $this->live()->events->trigger('order.shipped', $this->subscriber, ['order_id' => '4821']);

        self::assertStringStartsWith('evt_', $result->eventId);
        self::assertSame('accepted', $result->status);
        self::assertFalse($result->replayed);
    }

    public function testRecognisesTheSameIdempotencyKeyAsAReplay(): void
    {
        $key = 'live-'.bin2hex(random_bytes(8));
        $live = $this->live();

        $first = $live->events->trigger('order.shipped', $this->subscriber, ['order_id' => '1'], idempotencyKey: $key);
        $second = $live->events->trigger('order.shipped', $this->subscriber, ['order_id' => '1'], idempotencyKey: $key);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->eventId, $second->eventId);
    }

    public function testAnEmptyPayloadIsAcceptedBecauseItIsSentAsAnObject(): void
    {
        // The PHP trap: json_encode([]) is `[]`, which the API refuses. This is the only check that proves the fix is the server's format.
        $result = $this->live()->events->trigger('order.shipped', $this->subscriber);

        self::assertStringStartsWith('evt_', $result->eventId);
    }

    public function testCreatesASubscriberDescribedInlineOnTheFly(): void
    {
        $result = $this->live()->events->trigger('order.shipped', new Subscriber(self::unique(), email: 'live@example.test', locale: 'fr'));

        self::assertStringStartsWith('evt_', $result->eventId);
    }

    public function testAcceptsAListOfRecipients(): void
    {
        $result = $this->live()->events->trigger('order.shipped', [$this->subscriber, new Subscriber(self::unique())]);

        self::assertStringStartsWith('evt_', $result->eventId);
    }

    public function testAcceptsTheSchedulingAndActorFields(): void
    {
        $result = $this->live()->events->trigger(
            'order.shipped',
            $this->subscriber,
            ['when' => new \DateTimeImmutable()],
            actor: new \Hermesi\Actor(externalId: $this->subscriber, name: 'Ada'),
            sendAt: new \DateTimeImmutable('+1 hour'),
        );

        self::assertStringStartsWith('evt_', $result->eventId);
    }

    public function testAnswersARecipientThatIsNotASubscriberWithANotFoundException(): void
    {
        try {
            $this->live()->events->trigger('order.shipped', 'nobody_'.bin2hex(random_bytes(8)));
            self::fail('accepted');
        } catch (NotFoundException $e) {
            self::assertSame('subscriber_not_found', $e->errorCode);
            self::assertStringStartsWith('req_', $e->requestId);
        }
    }

    public function testAnswersAnEventNameItDoesNotAcceptWithAValidationException(): void
    {
        try {
            $this->live()->events->trigger('notadottedname', $this->subscriber);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertContains($e->status, [400, 422]);
        }
    }

    public function testAnswersAWrongKeyWithAnAuthenticationException(): void
    {
        $wrong = new Hermesi(apiKey: 'hm_sk_prod_not_a_real_key', baseUrl: $this->url, retry: new RetryPolicy(maxRetries: 0));

        $this->expectException(AuthenticationException::class);

        $wrong->events->trigger('order.shipped', $this->subscriber);
    }

    public function testMintsAPreferenceLink(): void
    {
        $link = $this->live()->subscribers->preferenceLink($this->subscriber);

        self::assertStringStartsWith('http', $link->url);
        self::assertStringContainsString('/preferences/', $link->url);
    }

    public function testMintsAPreferenceLinkForASubscriberWhoseIdHasCharactersAPathTreatsSpecially(): void
    {
        // The same class of id once made the server answer 404 for a subscriber that exists.
        $id = 'team/'.self::unique().' é?#';
        $this->live()->events->trigger('order.shipped', new Subscriber($id));

        $link = $this->live()->subscribers->preferenceLink($id);

        self::assertStringContainsString('/preferences/', $link->url);
    }

    public function testMintsATokenTheClientApiAcceptsAndOneForAnotherEnvironmentIsRefused(): void
    {
        // The token format is the one thing the server verifies cryptographically, so only the real server can say it is right.
        $status = function (string $token): int {
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Authorization: Bearer {$this->public}\r\nX-Hermesi-Subscriber-Token: $token\r\n"]]);
            file_get_contents($this->url.'/v1/client/inbox/counts', false, $context);
            preg_match('#HTTP/\S+ (\d+)#', (string) ($http_response_header[0] ?? ''), $m);

            return (int) ($m[1] ?? 0);
        };

        self::assertSame(200, $status($this->live()->tokens->mint($this->subscriber, environmentId: $this->environment)));
        self::assertSame(401, $status($this->live()->tokens->mint($this->subscriber, environmentId: 'env_not_this_one')));
    }

    public function testReportsAnUnreachableServerAsAConnectionException(): void
    {
        $lost = new Hermesi(apiKey: $this->secret, baseUrl: 'http://127.0.0.1:9', retry: new RetryPolicy(maxRetries: 0), timeout: 2.0);

        $this->expectException(ConnectionException::class);

        $lost->events->trigger('order.shipped', $this->subscriber);
    }
}

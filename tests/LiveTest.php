<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Exception\AuthenticationException;
use Hermesi\Exception\ConflictException;
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
 * The subscriber must already exist in that environment. The tests that send a direct message or write preferences also need, in that
 * environment, a published `sms` template whose key is HERMESI_LIVE_SMS_TEMPLATE (its text may use `{{ payload.code }}`) and a
 * non-critical category whose key is HERMESI_LIVE_CATEGORY; without them those tests are skipped.
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

    public function testAnEventIsReadBackWithItsNotification(): void
    {
        $sent = $this->live()->events->trigger('order.shipped', $this->subscriber, ['order_id' => 'live']);

        $run = $this->live()->events->get($sent->eventId);

        self::assertSame([$sent->eventId, 'order.shipped'], [$run->eventId, $run->name]);
        self::assertSame(['order_id' => 'live'], $run->payload);
        self::assertSame([$this->subscriber], array_map(static fn ($n): string => $n->externalId, $run->notifications));
    }

    public function testAnEventThatDoesNotExistIsNotFound(): void
    {
        try {
            $this->live()->events->get('evt_01DOESNOTEXIST00000000000');
            self::fail('found');
        } catch (NotFoundException $e) {
            self::assertSame('event_not_found', $e->errorCode);
        }
    }

    public function testASubscriberIsCreatedReadUpdatedAndDeleted(): void
    {
        $live = $this->live();
        $id = self::unique();

        $created = $live->subscribers->put($id, ['email' => 'Live@Example.test', 'first_name' => 'Live', 'locale' => 'fr', 'data' => ['plan' => 'pro', 'seats' => 3]]);
        self::assertSame($id, $created->externalId);
        self::assertStringStartsWith('sub_', $created->id);
        self::assertSame('live@example.test', $created->email, 'stored lower-cased');
        self::assertSame(['plan' => 'pro', 'seats' => 3], $created->data);

        $unchanged = $live->subscribers->put($id, ['locale' => 'en']);
        self::assertSame(['en', 'Live', 'live@example.test'], [$unchanged->locale, $unchanged->firstName, $unchanged->email], 'a key left out is left alone');

        $cleared = $live->subscribers->put($id, ['first_name' => null]);
        self::assertSame([null, 'live@example.test'], [$cleared->firstName, $cleared->email], 'null clears one field and only that');

        self::assertSame(['plan' => 'free'], $live->subscribers->put($id, ['data' => ['plan' => 'free']])->data, 'data replaces, it is not merged');
        self::assertSame(['plan' => 'free'], $live->subscribers->get($id)->data);
        self::assertSame('+237690000000', $live->subscribers->patch($id, ['phone_e164' => '+237690000000'])->phoneE164);

        $live->subscribers->delete($id);
        $live->subscribers->delete($id);
        $this->expectException(NotFoundException::class);
        $live->subscribers->get($id);
    }

    public function testAnEmptyPutIsAcceptedBecauseItIsSentAsAnObject(): void
    {
        $id = self::unique();

        self::assertSame($id, $this->live()->subscribers->put($id)->externalId);
        $this->live()->subscribers->delete($id);
    }

    public function testAValueOfTheWrongShapeIsAValidationExceptionNamingTheField(): void
    {
        try {
            $this->live()->subscribers->put(self::unique(), ['phone_e164' => '690000000']);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertTrue([] !== array_filter($e->details, static fn ($d): bool => str_contains((string) $d->field, 'phone_e164')));
        }
    }

    public function testPatchingASubscriberThatDoesNotExistIsNotFound(): void
    {
        try {
            $this->live()->subscribers->patch(self::unique(), ['locale' => 'en']);
            self::fail('accepted');
        } catch (NotFoundException $e) {
            self::assertSame('subscriber_not_found', $e->errorCode);
        }
    }

    public function testAnIdWithCharactersAPathTreatsSpeciallyWorksForEverySubscriberCall(): void
    {
        $live = $this->live();
        $id = 'team/'.self::unique().' é?#';

        $live->subscribers->put($id, ['locale' => 'fr']);

        self::assertSame($id, $live->subscribers->get($id)->externalId);
        $live->subscribers->registerChannel($id, 'push', 'tok-1');
        self::assertSame(['tok-1'], array_map(static fn ($c): string => $c->identifier, $live->subscribers->get($id)->channels));
        $live->subscribers->delete($id);
    }

    public function testADeviceTokenIsRegisteredListedAndRemoved(): void
    {
        $live = $this->live();
        $id = self::unique();
        $live->subscribers->put($id);

        $first = $live->subscribers->registerChannel($id, 'push', 'fcm-token-1', ['platform' => 'android']);
        $again = $live->subscribers->registerChannel($id, 'push', 'fcm-token-1', ['platform' => 'android']);

        self::assertSame(['active', 'active'], [$first->state, $again->state]);
        self::assertSame([['push', 'fcm-token-1', ['platform' => 'android']]], array_map(static fn ($c): array => [$c->channel, $c->identifier, $c->metadata], $live->subscribers->get($id)->channels));
        $live->subscribers->removeChannel($id, 'push', 'fcm-token-1');
        $live->subscribers->removeChannel($id, 'push', 'fcm-token-1');
        self::assertSame([], $live->subscribers->get($id)->channels);
        $live->subscribers->delete($id);
    }

    public function testAnIdentifierThatIsAUrlSurvivesTheRoundTrip(): void
    {
        // A Web Push endpoint is a URL: it has slashes, which a path segment can only carry percent-encoded.
        $live = $this->live();
        $id = self::unique();
        $live->subscribers->put($id);
        $identifier = 'https://fcm.googleapis.com/fcm/send/abc:APA91b/def';

        $live->subscribers->registerChannel($id, 'push', $identifier, ['transport' => 'fcm']);
        $live->subscribers->removeChannel($id, 'push', $identifier);

        self::assertSame([], $live->subscribers->get($id)->channels);
        $live->subscribers->delete($id);
    }

    public function testPreferencesAreWrittenReadAndRemoved(): void
    {
        $category = (string) getenv('HERMESI_LIVE_CATEGORY');
        if ('' === $category) {
            self::markTestSkipped('set HERMESI_LIVE_CATEGORY to a non-critical category key');
        }
        $live = $this->live();
        $id = self::unique();
        $live->subscribers->put($id);

        $after = $live->subscribers->updatePreferences($id, global: ['sms' => false], categories: [$category => ['email' => false, 'push' => false]]);
        self::assertSame([['sms' => false], [$category => ['email' => false, 'push' => false]]], [$after->global, $after->categories]);

        $removed = $live->subscribers->updatePreferences($id, global: ['sms' => null], categories: [$category => ['email' => null]]);
        self::assertSame([[], [$category => ['push' => false]]], [$removed->global, $removed->categories]);
        self::assertEquals($removed, $live->subscribers->preferences($id));
        $live->subscribers->delete($id);
    }

    public function testAnUnknownCategoryRefusesTheWholePreferenceUpdate(): void
    {
        $live = $this->live();
        $id = self::unique();
        $live->subscribers->put($id);

        try {
            $live->subscribers->updatePreferences($id, global: ['sms' => false], categories: ['no_such_category' => ['email' => false]]);
            self::fail('accepted');
        } catch (NotFoundException $e) {
            self::assertSame('category_not_found', $e->errorCode);
        }
        self::assertSame([], $live->subscribers->preferences($id)->global, 'nothing was applied');
        $live->subscribers->delete($id);
    }

    public function testADirectMessageIsSentReplayedAndReadBack(): void
    {
        $template = (string) getenv('HERMESI_LIVE_SMS_TEMPLATE');
        if ('' === $template) {
            self::markTestSkipped('set HERMESI_LIVE_SMS_TEMPLATE to the key of a published sms template');
        }
        $live = $this->live();
        $id = self::unique();
        $live->subscribers->put($id, ['phone_e164' => '+237690000001']);
        $key = 'live-'.bin2hex(random_bytes(8));

        $first = $live->messages->send('sms', $id, $template, data: ['code' => '480219'], idempotencyKey: $key);
        $second = $live->messages->send('sms', $id, $template, data: ['code' => '480219'], idempotencyKey: $key);

        self::assertSame(['queued', false], [$first->status, $first->replayed]);
        self::assertStringStartsWith('msg_', $first->messageId);
        self::assertTrue($second->replayed);
        self::assertSame($first->messageId, $second->messageId);
        $message = $live->messages->get($first->messageId);
        self::assertSame([$first->messageId, 'sms'], [$message->id, $message->channel]);
        try {
            $live->messages->send('sms', $id, $template, data: ['code' => '111111'], idempotencyKey: $key);
            self::fail('accepted');
        } catch (ConflictException) {
        }
        $live->subscribers->delete($id);
    }

    public function testADirectMessageToSomeoneWithNoPhoneIsReportedNotThrown(): void
    {
        $template = (string) getenv('HERMESI_LIVE_SMS_TEMPLATE');
        if ('' === $template) {
            self::markTestSkipped('set HERMESI_LIVE_SMS_TEMPLATE to the key of a published sms template');
        }
        $live = $this->live();
        $id = self::unique();
        $live->subscribers->put($id, ['email' => 'nophone@example.test']);

        $result = $live->messages->send('sms', $id, $template, data: ['code' => '1']);

        self::assertSame(['skipped', 'no_channel_identity'], [$result->status, $result->messages[0]->reason]);
        $live->subscribers->delete($id);
    }

    public function testADirectMessageWithAnUnknownTemplateIsNotFound(): void
    {
        try {
            $this->live()->messages->send('sms', $this->subscriber, 'no-such-template');
            self::fail('accepted');
        } catch (NotFoundException $e) {
            self::assertSame('template_not_found', $e->errorCode);
        }
    }

    public function testTheServerRefusesInlineContentInsteadOfIgnoringIt(): void
    {
        // The SDK has no `content` argument at all, so this is asserted against the server directly.
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'ignore_errors' => true,
            'header' => "Authorization: Bearer {$this->secret}\r\nContent-Type: application/json\r\n",
            'content' => json_encode(['channel' => 'sms', 'recipient' => $this->subscriber, 'template' => 'x', 'content' => ['body' => 'hi']], \JSON_THROW_ON_ERROR),
        ]]);
        $body = (string) file_get_contents($this->url.'/v1/messages', false, $context);
        preg_match('#HTTP/\S+ (\d+)#', (string) ($http_response_header[0] ?? ''), $m);

        self::assertSame(422, (int) ($m[1] ?? 0));
        self::assertStringContainsString('inline_content_not_supported', $body);
    }
}

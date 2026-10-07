<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Actor;
use Hermesi\Exception\ApiException;
use Hermesi\Exception\AuthenticationException;
use Hermesi\Exception\ConflictException;
use Hermesi\Exception\ForbiddenException;
use Hermesi\Exception\HermesiException;
use Hermesi\Exception\NotFoundException;
use Hermesi\Exception\RateLimitException;
use Hermesi\Exception\ServerException;
use Hermesi\Exception\ValidationException;
use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Hermesi\Subscriber;
use Hermesi\Tests\Support\Plain;
use Hermesi\Tests\Support\Status;
use Hermesi\Tests\Support\TestServer;
use PHPUnit\Framework\Attributes\DataProvider;

final class EventsTest extends ServerTestCase
{
    public function testPublishesAnEventWithTheSecretKeyAndAJsonBodyAndReadsTheAnswer(): void
    {
        $result = $this->client()->events->trigger('order.shipped', 'user_8821', ['order_id' => '4821']);

        $request = self::$server->last();
        self::assertSame('POST', $request['method']);
        self::assertSame('/v1/events', $request['path']);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertMatchesRegularExpression('#^hermesi-php/\d+\.\d+\.\d+$#', $request['headers']['user-agent']);
        self::assertSame(['name' => 'order.shipped', 'recipient' => 'user_8821', 'payload' => ['order_id' => '4821']], $this->lastBody());
        self::assertSame('evt_01K2QH8F3T7Y0RJ4N5V6WX8ZQD', $result->eventId);
        self::assertSame('accepted', $result->status);
        self::assertSame([], $result->warnings);
        self::assertCount(1, $result->notifications);
        self::assertSame(['not_1', 'sub_1', 'order-shipped'], [$result->notifications[0]->id, $result->notifications[0]->subscriberId, $result->notifications[0]->workflow]);
    }

    public function testGeneratesAnIdempotencyKeyWhenNoneIsGivenAndReportsIt(): void
    {
        $result = $this->client()->events->trigger('order.shipped', 'user_1');

        $sent = self::$server->last()['headers']['idempotency-key'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $sent);
        self::assertSame($sent, $result->idempotencyKey);
    }

    public function testUsesTheCallersIdempotencyKeyAsIs(): void
    {
        $result = $this->client()->events->trigger('order.shipped', 'user_1', idempotencyKey: 'order-4821-shipped');

        self::assertSame('order-4821-shipped', self::$server->last()['headers']['idempotency-key']);
        self::assertSame('order-4821-shipped', $result->idempotencyKey);
    }

    public function testTwoEventsGetTwoDifferentGeneratedKeys(): void
    {
        $client = $this->client();
        $client->events->trigger('order.shipped', 'user_1');
        $client->events->trigger('order.shipped', 'user_1');

        $keys = array_map(static fn (array $r): string => $r['headers']['idempotency-key'], self::$server->requests());
        self::assertCount(2, array_unique($keys));
    }

    public function testSaysWhenTheServerReplayedAnEarlierAnswerAndNotOtherwise(): void
    {
        self::$server->enqueue(['status' => 202, 'body' => TestServer::ACCEPTED, 'headers' => ['Idempotency-Replayed' => 'true']]);
        $client = $this->client();

        self::assertTrue($client->events->trigger('order.shipped', 'user_1', idempotencyKey: 'k')->replayed);
        self::assertFalse($client->events->trigger('order.shipped', 'user_1')->replayed);
    }

    public function testSendsASubscriberDescribedInlineWithTheWireNamesAndLeavesOutWhatIsNotSet(): void
    {
        $this->client()->events->trigger('order.shipped', new Subscriber('cust_1', email: 'a@example.test', locale: 'fr', data: ['plan' => 'pro']));

        self::assertSame(['external_id' => 'cust_1', 'email' => 'a@example.test', 'locale' => 'fr', 'data' => ['plan' => 'pro']], $this->lastBody()['recipient']);
    }

    public function testSendsAListOfRecipientsOfEitherKind(): void
    {
        $this->client()->events->trigger('order.shipped', ['user_1', new Subscriber('user_2', phoneE164: '+237670000001')]);

        self::assertSame(['user_1', ['external_id' => 'user_2', 'phone_e164' => '+237670000001']], $this->lastBody()['recipient']);
    }

    public function testRefusesARecipientThatIsAMapNotAList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client()->events->trigger('order.shipped', ['a' => 'user_1']); // @phpstan-ignore argument.type
    }

    public function testSendsTheOptionalFieldsOnlyWhenGiven(): void
    {
        $client = $this->client();
        $client->events->trigger('order.shipped', 'user_1');
        $keys = array_keys($this->lastBody());
        sort($keys);
        self::assertSame(['name', 'payload', 'recipient'], $keys);

        $client->events->trigger(
            'order.shipped',
            'user_1',
            actor: new Actor(externalId: 'user_9', name: 'Ada'),
            delay: '15m',
            override: ['email' => ['subject' => 'Hi']],
            tenant: 'acme',
        );
        self::assertEquals([
            'name' => 'order.shipped',
            'recipient' => 'user_1',
            'payload' => [],
            'actor' => ['external_id' => 'user_9', 'name' => 'Ada'],
            'delay' => '15m',
            'override' => ['email' => ['subject' => 'Hi']],
            'tenant' => 'acme',
        ], $this->lastBody());
    }

    public function testAnEmptyPayloadIsAnObjectNotAnArray(): void
    {
        $this->client()->events->trigger('order.shipped', 'user_1');

        // The mistake PHP makes by default: json_encode([]) is `[]`, and the API needs `{}`.
        self::assertStringContainsString('"payload":{}', self::$server->last()['body']);
    }

    public function testAnEmptyOverrideAndEmptySubscriberDataAreObjectsToo(): void
    {
        $this->client()->events->trigger('order.shipped', new Subscriber('c', data: []), override: []);

        $body = self::$server->last()['body'];
        self::assertStringContainsString('"data":{}', $body);
        self::assertStringContainsString('"override":{}', $body);
    }

    public function testRefusesAListWhereAnObjectIsNeeded(): void
    {
        foreach ([['payload' => ['a', 'b']], ['override' => ['a']]] as $arguments) {
            try {
                $this->client()->events->trigger('order.shipped', 'user_1', ...$arguments); // @phpstan-ignore argument.type
                self::fail('a list was accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('associative array', $e->getMessage());
            }
        }
        self::assertSame([], self::$server->requests());
    }

    public function testSendAtTakesADateTimeOrAStringAndKeepsTheOffset(): void
    {
        $client = $this->client();

        $client->events->trigger('order.shipped', 'user_1', sendAt: new \DateTimeImmutable('2026-12-01T09:30:00+01:00'));
        self::assertSame('2026-12-01T09:30:00.000+01:00', $this->lastBody()['send_at']);

        $client->events->trigger('order.shipped', 'user_1', sendAt: '2026-12-01T10:30:00+01:00');
        self::assertSame('2026-12-01T10:30:00+01:00', $this->lastBody()['send_at']);
    }

    public function testSerialisesWhatAPayloadCommonlyHolds(): void
    {
        $this->client()->events->trigger('order.shipped', 'user_1', [
            'at' => new \DateTimeImmutable('2026-10-04T12:00:00+00:00'),
            'status' => Status::Shipped,
            'label' => new class implements \Stringable {
                public function __toString(): string
                {
                    return 'Express';
                }
            },
            'price' => 12.0,
            'url' => 'https://example.com/a/b',
            'name' => 'Zoé 日本',
            'nested' => ['list' => [1, 'two', null, ['three' => 3]], 'flag' => true],
            'plain' => (object) ['a' => 1],
            'json' => new class implements \JsonSerializable {
                /** @return array<string, int> */
                public function jsonSerialize(): array
                {
                    return ['k' => 7];
                }
            },
        ]);

        $raw = self::$server->last()['body'];
        self::assertStringContainsString('"price":12.0', $raw, 'zero fraction kept');
        self::assertStringContainsString('"url":"https://example.com/a/b"', $raw, 'slashes not escaped');
        self::assertStringContainsString('"name":"Zoé 日本"', $raw, 'unicode not escaped');
        self::assertEquals([
            'at' => '2026-10-04T12:00:00.000+00:00',
            'status' => 'shipped',
            'label' => 'Express',
            'price' => 12.0,
            'url' => 'https://example.com/a/b',
            'name' => 'Zoé 日本',
            'nested' => ['list' => [1, 'two', null, ['three' => 3]], 'flag' => true],
            'plain' => ['a' => 1],
            'json' => ['k' => 7],
        ], $this->lastBody()['payload']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unsendable(): iterable
    {
        yield 'NAN' => [\NAN];
        yield 'INF' => [\INF];
        yield 'a closure' => [static fn (): int => 1];
        yield 'a resource' => [fopen('php://memory', 'r')];
        yield 'an enum without a value' => [Plain::One];
        yield 'an arbitrary object' => [new \ArrayObject([1])];
        yield 'invalid UTF-8' => ["bad \xB1 byte"];
        yield 'nested NAN' => [['deep' => ['list' => [['x' => \NAN]]]]];
    }

    #[DataProvider('unsendable')]
    public function testRefusesWhatJsonWouldLoseOrDistortBeforeSendingAnything(mixed $value): void
    {
        try {
            $this->client()->events->trigger('order.shipped', 'user_1', ['value' => $value]);
            self::fail('the value was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('payload.value', $e->getMessage(), 'names where the problem is');
        }
        self::assertSame([], self::$server->requests());
    }

    public function testRequiresAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name is required');

        $this->client()->events->trigger('', 'user_1');
    }

    /** @return iterable<string, array{int, class-string<ApiException>, string}> */
    public static function refusals(): iterable
    {
        yield '400' => [400, ValidationException::class, 'validation_error'];
        yield '422' => [422, ValidationException::class, 'validation_error'];
        yield '401' => [401, AuthenticationException::class, 'invalid_api_key'];
        yield '403' => [403, ForbiddenException::class, 'forbidden'];
        yield '404' => [404, NotFoundException::class, 'subscriber_not_found'];
        yield '429' => [429, RateLimitException::class, 'rate_limited'];
        yield '500' => [500, ServerException::class, 'internal_error'];
        yield '503' => [503, ServerException::class, 'unavailable'];
        yield '409' => [409, ConflictException::class, 'idempotency_key_reused'];
    }

    /** @param class-string<ApiException> $class */
    #[DataProvider('refusals')]
    public function testTurnsARefusalIntoTheRightException(int $status, string $class, string $code): void
    {
        self::$server->enqueue(['status' => $status, 'body' => TestServer::error($code)]);

        try {
            $this->client()->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($status, $e->status);
            self::assertSame($code, $e->errorCode);
            self::assertSame('req_abc123', $e->requestId);
        }
    }

    public function testTheMessageNamesTheCodeAndTheRequestToQuoteAndTheDetailsAreKept(): void
    {
        self::$server->enqueue(['status' => 422, 'body' => TestServer::error('validation_error', ['detail' => [['field' => 'recipient', 'issue' => 'required'], 'junk', ['field' => '', 'issue' => 'x']]])]);

        try {
            $this->client()->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ValidationException $e) {
            self::assertStringContainsString('validation_error', $e->getMessage());
            self::assertStringContainsString('req_abc123', $e->getMessage());
            self::assertStringContainsString('HTTP 422', $e->getMessage());
            self::assertCount(2, $e->details);
            self::assertSame(['recipient', 'required'], [$e->details[0]->field, $e->details[0]->issue]);
            self::assertSame([null, 'x'], [$e->details[1]->field, $e->details[1]->issue]);
            self::assertSame('https://docs.example/errors/validation_error', $e->docUrl);
        }
    }

    public function testA429CarriesWhatTheServerAskedForInSeconds(): void
    {
        self::$server->enqueue(['status' => 429, 'body' => TestServer::error('rate_limited'), 'headers' => ['Retry-After' => '7']]);

        try {
            $this->client()->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (RateLimitException $e) {
            self::assertSame(7.0, $e->retryAfter);
            self::assertTrue($e->isRetryable());
        }
    }

    public function testAnAnswerThatIsNotTheErrorEnvelopeIsReportedAsSuchNotGuessedAt(): void
    {
        self::$server->enqueue(['status' => 502, 'body' => '<html>Bad gateway</html>']);

        try {
            $this->client()->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ServerException $e) {
            self::assertSame('sdk_error', $e->errorType);
            self::assertSame('unexpected_response', $e->errorCode);
            self::assertSame('', $e->requestId);
        }
    }

    public function testASuccessThatIsNotAnEventOrNotJsonIsAnErrorNotAMadeUpResult(): void
    {
        foreach ([['status' => 202, 'body' => ['hello' => 'world']], ['status' => 200, 'body' => 'OK']] as $answer) {
            self::$server->enqueue($answer);
            try {
                $this->client()->events->trigger('order.shipped', 'user_1');
                self::fail('a made-up result');
            } catch (ApiException $e) {
                self::assertSame('unexpected_response', $e->errorCode);
            }
        }
    }

    public function testDoesNotFollowARedirectWhichWouldTurnThePostIntoAGetAndLoseTheEvent(): void
    {
        self::$server->enqueue(['status' => 301, 'headers' => ['Location' => '/elsewhere'], 'body' => '']);

        try {
            $this->client()->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertSame(301, $e->status);
        }
        self::assertCount(1, self::$server->requests());
    }

    public function testA3xxIsNeverASuccessEvenWithAnEventInItsBody(): void
    {
        self::$server->enqueue(['status' => 302, 'body' => TestServer::ACCEPTED]);

        try {
            $this->client()->events->trigger('order.shipped', 'user_1');
            self::fail('a redirect was taken for an accepted event');
        } catch (ApiException $e) {
            self::assertSame(302, $e->status);
        }
    }

    public function testEveryExceptionSharesOneBaseACallerCanCatch(): void
    {
        self::$server->enqueue(['status' => 404, 'body' => TestServer::error('subscriber_not_found')]);

        $this->expectException(HermesiException::class);

        $this->client()->events->trigger('order.shipped', 'user_1');
    }

    public function testUsesTheHttpClientItWasGiven(): void
    {
        $client = new Hermesi(
            apiKey: self::KEY,
            baseUrl: self::$server->url().'/',
            retry: new RetryPolicy(maxRetries: 0),
            httpClient: new \GuzzleHttp\Client(['http_errors' => false, 'headers' => ['X-From-Test' => 'yes']]),
        );

        $client->events->trigger('order.shipped', 'user_1');

        self::assertSame('yes', self::$server->last()['headers']['x-from-test']);
        self::assertSame('/v1/events', self::$server->last()['path'], 'the trailing slash of the base URL is dropped');
    }
}

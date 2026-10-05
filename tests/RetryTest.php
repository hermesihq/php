<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Exception\ApiException;
use Hermesi\Exception\ConnectionException;
use Hermesi\Exception\ServerException;
use Hermesi\Exception\ValidationException;
use Hermesi\RetryPolicy;
use Hermesi\Tests\Support\TestServer;
use PHPUnit\Framework\Attributes\DataProvider;

final class RetryTest extends ServerTestCase
{
    public function testRetriesA5xxAndSucceedsWithTheSameIdempotencyKeyEveryTime(): void
    {
        self::$server->enqueue(['status' => 503, 'body' => TestServer::error('unavailable')], ['status' => 500, 'body' => TestServer::error('boom')]);

        $result = $this->client(new RetryPolicy())->events->trigger('order.shipped', 'user_1');

        self::assertSame('evt_01K2QH8F3T7Y0RJ4N5V6WX8ZQD', $result->eventId);
        $requests = self::$server->requests();
        self::assertCount(3, $requests);
        self::assertCount(1, array_unique(array_map(static fn (array $r): string => $r['headers']['idempotency-key'], $requests)));
        self::assertSame($requests[0]['headers']['idempotency-key'], $result->idempotencyKey);
        self::assertCount(2, $this->waits);
    }

    public function testWaitsExactlyAsLongAsTheServerAsksOnA429(): void
    {
        self::$server->enqueue(['status' => 429, 'body' => TestServer::error('rate_limited'), 'headers' => ['Retry-After' => '2']]);

        $this->client(new RetryPolicy())->events->trigger('order.shipped', 'user_1');

        self::assertSame([2.0], $this->waits);
    }

    public function testDoesNotSleepThroughARetryAfterLongerThanItIsWillingToWait(): void
    {
        self::$server->enqueue(['status' => 429, 'body' => TestServer::error('rate_limited'), 'headers' => ['Retry-After' => '600']]);

        try {
            $this->client(new RetryPolicy())->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertSame(429, $e->status);
            self::assertSame(600.0, $e->retryAfter);
        }
        self::assertSame([], $this->waits);
        self::assertCount(1, self::$server->requests());
    }

    public function testGivesUpAfterTheConfiguredRetriesAndThrowsTheLastAnswer(): void
    {
        self::$server->enqueue(...array_map(static fn (int $n): array => ['status' => 503, 'body' => TestServer::error('unavailable_'.$n)], [1, 2, 3, 4, 5]));

        try {
            $this->client(new RetryPolicy(maxRetries: 2))->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ServerException $e) {
            self::assertSame('unavailable_3', $e->errorCode);
        }
        self::assertCount(3, self::$server->requests());
    }

    public function testMaxRetriesZeroTurnsRetryingOff(): void
    {
        self::$server->enqueue(['status' => 503, 'body' => TestServer::error('unavailable')]);

        try {
            $this->client(new RetryPolicy(maxRetries: 0))->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ServerException) {
            self::assertCount(1, self::$server->requests());
            self::assertSame([], $this->waits);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function refusalsThatAreNeverRetried(): iterable
    {
        foreach ([400, 401, 403, 404, 409, 422] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('refusalsThatAreNeverRetried')]
    public function testNeverRetriesARefusalTheSameRequestWouldGetAgain(int $status): void
    {
        self::$server->enqueue(['status' => $status, 'body' => TestServer::error('refused')]);

        try {
            $this->client(new RetryPolicy())->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ApiException) {
            self::assertCount(1, self::$server->requests());
        }
    }

    public function testAValidationErrorAfterARetryIsStillThrownAsOne(): void
    {
        self::$server->enqueue(['status' => 503, 'body' => TestServer::error('unavailable')], ['status' => 422, 'body' => TestServer::error('validation_error')]);

        $this->expectException(ValidationException::class);

        try {
            $this->client(new RetryPolicy())->events->trigger('order.shipped', 'user_1');
        } finally {
            self::assertCount(2, self::$server->requests());
        }
    }

    public function testRetriesAConnectionTheServerDrops(): void
    {
        self::$server->enqueue(['destroy' => true]);

        $result = $this->client(new RetryPolicy())->events->trigger('order.shipped', 'user_1');

        self::assertSame('accepted', $result->status);
        $requests = self::$server->requests();
        self::assertCount(2, $requests);
        self::assertSame($requests[0]['headers']['idempotency-key'], $requests[1]['headers']['idempotency-key']);
    }

    public function testRetriesAnAnswerCutOffHalfwayThroughItsBody(): void
    {
        self::$server->enqueue(['status' => 202, 'body' => TestServer::ACCEPTED, 'truncate' => true]);

        $result = $this->client(new RetryPolicy())->events->trigger('order.shipped', 'user_1');

        self::assertSame('evt_01K2QH8F3T7Y0RJ4N5V6WX8ZQD', $result->eventId);
        self::assertCount(2, self::$server->requests());
    }

    public function testRetriesATimeoutAndEachAttemptGetsItsOwn(): void
    {
        self::$server->enqueue(['hang' => true]);

        $result = $this->client(new RetryPolicy(), timeout: 0.4)->events->trigger('order.shipped', 'user_1');

        self::assertSame('accepted', $result->status);
        self::assertCount(2, self::$server->requests());
    }

    public function testAServerThatNeverAnswersIsAConnectionExceptionNotAnApiException(): void
    {
        self::$server->enqueue(['hang' => true], ['hang' => true]);

        try {
            $this->client(new RetryPolicy(maxRetries: 1), timeout: 0.3)->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('Could not reach Hermesi', $e->getMessage());
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
    }

    public function testAConnectionThatNeverComesBackIsAConnectionExceptionCarryingItsCause(): void
    {
        try {
            $this->client(new RetryPolicy(maxRetries: 2), TestServer::deadUrl())->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ConnectionException $e) {
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
        self::assertCount(2, $this->waits);
    }

    public function testBackoffGrowsAndStaysInsideItsBounds(): void
    {
        self::$server->enqueue(...array_fill(0, 5, ['status' => 503, 'body' => TestServer::error('unavailable')]));

        try {
            $this->client(new RetryPolicy(maxRetries: 4, baseDelay: 0.1, maxDelay: 0.3))->events->trigger('order.shipped', 'user_1');
        } catch (ServerException) {
        }

        $ceilings = [0.1, 0.2, 0.3, 0.3];
        self::assertCount(4, $this->waits);
        foreach ($this->waits as $i => $wait) {
            self::assertGreaterThanOrEqual($ceilings[$i] / 2 - 1e-9, $wait);
            self::assertLessThanOrEqual($ceilings[$i] + 1e-9, $wait);
        }
    }

    public function testRetriesAPreferenceLinkToo(): void
    {
        self::$server->enqueue(['status' => 503, 'body' => TestServer::error('unavailable')], ['status' => 200, 'body' => ['url' => 'https://h.example/preferences/abc']]);

        $link = $this->client(new RetryPolicy())->subscribers->preferenceLink('user_1');

        self::assertSame('https://h.example/preferences/abc', $link->url);
        self::assertCount(2, self::$server->requests());
    }

    public function testThePolicyStopsAtMaxRetries(): void
    {
        $policy = new RetryPolicy(maxRetries: 2);

        self::assertNotNull($policy->delay(1, null));
        self::assertNull($policy->delay(2, null));
        self::assertNull((new RetryPolicy(maxRetries: 0))->delay(0, null));
    }

    public function testThePolicyHonoursRetryAfterExactlyAndWithoutJitterWhateverTheDrawIs(): void
    {
        $policy = new RetryPolicy(maxRetries: 10, baseDelay: 0.1, maxDelay: 1.0, maxRetryAfter: 5.0);

        self::assertSame(3.0, $policy->delay(0, 3.0, static fn (): float => 0.0));
        self::assertSame(3.0, $policy->delay(0, 3.0, static fn (): float => 0.999));
        self::assertSame(0.0, $policy->delay(0, 0.0));
    }

    public function testThePolicyRefusesARetryAfterOverItsLimitAndTakesOneAtTheLimit(): void
    {
        $policy = new RetryPolicy(maxRetries: 10, maxRetryAfter: 5.0);

        self::assertNull($policy->delay(0, 5.001));
        self::assertSame(5.0, $policy->delay(0, 5.0));
    }

    public function testThePolicyNeverWaitsLessThanHalfTheCeilingAndNeverMoreThanAllOfIt(): void
    {
        $policy = new RetryPolicy(maxRetries: 10, baseDelay: 0.1, maxDelay: 1.0);

        self::assertEqualsWithDelta(0.05, $policy->delay(0, null, static fn (): float => 0.0), 1e-12);
        self::assertEqualsWithDelta(0.1, $policy->delay(0, null, static fn (): float => 1.0), 1e-12);
        self::assertEqualsWithDelta(0.4, $policy->delay(3, null, static fn (): float => 0.0), 1e-12);
        self::assertEqualsWithDelta(1.0, $policy->delay(9, null, static fn (): float => 1.0), 1e-12);
    }

    public function testRetryAfterIsReadAsSecondsAndNothingElse(): void
    {
        self::assertSame(5.0, RetryPolicy::parseRetryAfter('5'));
        self::assertSame(1.5, RetryPolicy::parseRetryAfter(' 1.5 '));
        self::assertSame(0.0, RetryPolicy::parseRetryAfter('0'));
        foreach (['', '   ', 'abc', '-1', '1e3', 'INF', 'NAN', '0x10', 'Wed, 21 Oct 2026 07:28:00 GMT', null] as $bad) {
            self::assertNull(RetryPolicy::parseRetryAfter($bad), var_export($bad, true));
        }
    }

    public function testRejectsSettingsThatMakeNoSense(): void
    {
        foreach ([
            static fn () => new RetryPolicy(maxRetries: -1),
            static fn () => new RetryPolicy(baseDelay: -1.0),
            static fn () => new RetryPolicy(maxDelay: \NAN),
            static fn () => new RetryPolicy(maxRetryAfter: -0.5),
        ] as $make) {
            try {
                $make();
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

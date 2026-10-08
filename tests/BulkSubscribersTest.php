<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Exception\ValidationException;
use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Hermesi\Tests\Support\TestServer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `$hermesi->subscribers->bulk()`: up to 1 000 upserts in one request (`POST /v1/subscribers/bulk`).
 *
 * Each row has to mean what the same `put` would, which for a sync job comes down to one distinction: a key present with `null` clears
 * the field and a key absent leaves it alone. And a typo is refused naming its row instead of being dropped, which in a bulk import is
 * a column of the file that is silently never applied. PHP adds a trap of its own: an empty `data` must go out as `{}`, not `[]`.
 */
final class BulkSubscribersTest extends ServerTestCase
{
    private const ANSWER = [
        'created' => 2,
        'updated' => 1,
        'subscribers' => [
            ['external_id' => 'user_1', 'id' => 'sub_1', 'status' => 'created'],
            ['external_id' => 'user_2', 'id' => 'sub_2', 'status' => 'updated'],
            ['external_id' => 'user_3', 'id' => 'sub_3', 'status' => 'created'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::$server->setDefault(['status' => 200, 'body' => self::ANSWER]);
    }

    public function testIsOnePostAndTheResultSaysWhatHappenedToEachRow(): void
    {
        $result = $this->client()->subscribers->bulk([
            ['external_id' => 'user_1', 'email' => 'a@example.cm', 'phone_e164' => '+237690000000', 'first_name' => 'A'],
            ['external_id' => 'user_2', 'locale' => 'fr', 'data' => ['plan' => 'pro']],
        ]);

        $request = self::$server->last();
        self::assertSame(['POST', '/v1/subscribers/bulk'], [$request['method'], $request['path']]);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
        self::assertArrayNotHasKey('idempotency-key', $request['headers']);
        self::assertSame(
            ['subscribers' => [
                ['external_id' => 'user_1', 'email' => 'a@example.cm', 'phone_e164' => '+237690000000', 'first_name' => 'A'],
                ['external_id' => 'user_2', 'locale' => 'fr', 'data' => ['plan' => 'pro']],
            ]],
            $this->lastBody(),
        );
        self::assertSame([2, 1], [$result->created, $result->updated], 'the two counts are not interchangeable');
        self::assertSame(
            [['user_1', 'sub_1', 'created'], ['user_2', 'sub_2', 'updated'], ['user_3', 'sub_3', 'created']],
            array_map(static fn ($r): array => [$r->externalId, $r->id, $r->status], $result->subscribers),
        );
    }

    public function testNullClearsAndAKeyLeftOutIsLeftAlone(): void
    {
        $this->client()->subscribers->bulk([['external_id' => 'u', 'phone_e164' => null, 'data' => null], ['external_id' => 'v'], ['external_id' => 'w', 'data' => []]]);

        self::assertSame('{"subscribers":[{"external_id":"u","phone_e164":null,"data":null},{"external_id":"v"},{"external_id":"w","data":{}}]}', self::$server->last()['body'], 'an empty data is an object, not a list');
    }

    public function testAnyIterableOfRowsWorksAGeneratorIncluded(): void
    {
        $rows = (static function (): \Generator {
            foreach ([0, 1, 2] as $i) {
                yield ['external_id' => 'u'.$i];
            }
        })();

        $this->client()->subscribers->bulk($rows);

        $sent = $this->lastBody();
        self::assertIsArray($sent['subscribers']);
        self::assertSame(['u0', 'u1', 'u2'], array_map(static fn ($row) => \is_array($row) ? $row['external_id'] : null, $sent['subscribers']));
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function refusedRows(): iterable
    {
        yield 'no rows' => [[], 'at least one subscriber'];
        yield 'no id' => [[['email' => 'a@example.cm']], 'row 0: external_id is required'];
        yield 'empty id' => [[['external_id' => 'a'], ['external_id' => '']], 'row 1: external_id is required'];
        yield 'numeric id' => [[['external_id' => 'a'], ['external_id' => 7]], 'row 1: external_id is required'];
        yield 'typo' => [[['external_id' => 'a', 'phone' => '+237690000000']], 'row 0: unknown subscriber field "phone"'];
        yield 'camel case' => [[['external_id' => 'a'], ['external_id' => 'b', 'phoneE164' => '+1']], 'row 1: unknown subscriber field "phoneE164"'];
        yield 'wrong type' => [[['external_id' => 'a', 'email' => 42]], 'row 0: email must be a string'];
        yield 'not an array' => [[['external_id' => 'a'], 'user_2'], 'row 1 must be an array'];
    }

    /** @param list<mixed> $rows */
    #[DataProvider('refusedRows')]
    public function testARowThatCannotBeRightIsRefusedNamingItBeforeAnyRequest(array $rows, string $message): void
    {
        try {
            $this->client()->subscribers->bulk($rows); // @phpstan-ignore argument.type
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertSame([], self::$server->requests());
        }
    }

    public function testTheServersRefusalListsEveryProblemWithItsRow(): void
    {
        self::$server->enqueue(['status' => 422, 'body' => TestServer::error('validation_error', ['detail' => [
            ['field' => 'body.subscribers.0.phone_e164', 'issue' => 'Value error, not an E.164 phone number'],
            ['field' => 'body.subscribers.2.locale', 'issue' => 'Value error, not a language tag'],
        ]])]);

        try {
            $this->client()->subscribers->bulk([['external_id' => 'a'], ['external_id' => 'b'], ['external_id' => 'c']]);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertSame(['body.subscribers.0.phone_e164', 'body.subscribers.2.locale'], array_map(static fn ($d): ?string => $d->field, $e->details));
        }
    }

    public function testIsRetriedOnA503LikeAnyIdempotentWrite(): void
    {
        self::$server->enqueue(['status' => 503, 'body' => TestServer::error('unavailable')], ['status' => 200, 'body' => self::ANSWER]);

        $result = $this->client(new RetryPolicy(maxRetries: 2))->subscribers->bulk([['external_id' => 'user_1']]);

        self::assertCount(2, self::$server->requests());
        self::assertSame(2, $result->created);
    }

    public function testAnAnswerWithoutTheListOfRowsIsNotBelieved(): void
    {
        self::$server->enqueue(['status' => 200, 'body' => ['created' => 3]]);

        $this->expectExceptionMessage('unexpected_response');

        $this->client()->subscribers->bulk([['external_id' => 'user_1']]);
    }

    public function testSimulateRecordsTheCallAnswersPlausiblyAndValidatesAsARealCallDoes(): void
    {
        $test = new Hermesi(simulate: true);

        $result = $test->subscribers->bulk([['external_id' => 'a', 'email' => 'a@example.cm'], ['external_id' => 'b']]);

        self::assertSame([2, 0], [$result->created, $result->updated]);
        self::assertSame([['a', 'created'], ['b', 'created']], array_map(static fn ($r): array => [$r->externalId, $r->status], $result->subscribers));
        $call = $test->simulatedCalls()[0];
        self::assertSame(['POST', '/v1/subscribers/bulk'], [$call->method, $call->path]);
        self::assertSame(['subscribers' => [['external_id' => 'a', 'email' => 'a@example.cm'], ['external_id' => 'b']]], $call->body);
        try {
            $test->subscribers->bulk([['external_id' => 'a', 'phone' => '+1']]);
            self::fail('accepted');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $test->simulatedCalls());
        }
    }
}

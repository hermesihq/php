<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Exception\NotFoundException;
use Hermesi\Tests\Support\TestServer;
use PHPUnit\Framework\Attributes\DataProvider;

final class SubscribersTest extends ServerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::$server->setDefault(['status' => 200, 'body' => ['url' => 'https://hermesi.example/preferences/tok_1']]);
    }

    public function testMintsALinkWithTheSecretKeyAndNoIdempotencyKey(): void
    {
        $link = $this->client()->subscribers->preferenceLink('user_8821');

        $request = self::$server->last();
        self::assertSame('https://hermesi.example/preferences/tok_1', $link->url);
        self::assertSame('POST', $request['method']);
        self::assertSame('/v1/subscribers/user_8821/preference-link', $request['path']);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
        self::assertArrayNotHasKey('idempotency-key', $request['headers']);
        self::assertSame('{}', $request['body']);
    }

    public function testEscapesTheIdInThePathSoItCannotReachAnotherEndpoint(): void
    {
        $this->client()->subscribers->preferenceLink('a/b c?d#e%f');

        self::assertSame('/v1/subscribers/a%2Fb%20c%3Fd%23e%25f/preference-link', self::$server->last()['path']);
    }

    /** @return iterable<string, array{string}> */
    public static function dotSegments(): iterable
    {
        yield 'one dot' => ['.'];
        yield 'two dots' => ['..'];
    }

    #[DataProvider('dotSegments')]
    public function testRefusesAnIdAUrlParserWouldResolveIntoAnotherPathEvenWhenEscaped(string $id): void
    {
        try {
            $this->client()->subscribers->preferenceLink($id);
            self::fail('accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], self::$server->requests());
        }
    }

    public function testRequiresAnId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('externalId is required');

        $this->client()->subscribers->preferenceLink('');
    }

    public function testAnUnknownSubscriberIsANotFoundException(): void
    {
        self::$server->enqueue(['status' => 404, 'body' => TestServer::error('subscriber_not_found')]);

        $this->expectException(NotFoundException::class);

        $this->client()->subscribers->preferenceLink('nobody');
    }

    public function testAnAnswerWithoutAUrlIsAnError(): void
    {
        self::$server->enqueue(['status' => 200, 'body' => ['nope' => true]]);

        $this->expectExceptionMessage('unexpected_response');

        $this->client()->subscribers->preferenceLink('user_1');
    }
}

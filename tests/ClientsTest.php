<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Hermesi\Hermesi;
use Hermesi\Internal\HttpClients;
use Hermesi\RetryPolicy;
use Hermesi\Tests\Support\TestServer;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/** The package talks PSR-18, so any client works. Each of these is a different one, against the same real server. */
final class ClientsTest extends ServerTestCase
{
    public function testWorksThroughSymfonyHttpClient(): void
    {
        $client = new Hermesi(
            apiKey: self::KEY,
            baseUrl: self::$server->url(),
            retry: new RetryPolicy(maxRetries: 0),
            httpClient: new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 5])),
        );

        $result = $client->events->trigger('order.shipped', 'user_1', ['order_id' => '1'], idempotencyKey: 'k');

        self::assertSame('evt_01K2QH8F3T7Y0RJ4N5V6WX8ZQD', $result->eventId);
        self::assertSame('Bearer '.self::KEY, self::$server->last()['headers']['authorization']);
        self::assertSame('k', self::$server->last()['headers']['idempotency-key']);
    }

    public function testWorksThroughAPsr18ClientWrittenInFiveLinesWithNoLibraryAtAll(): void
    {
        $own = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $seen = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen[] = $request;

                return new Response(202, [], (string) json_encode(TestServer::ACCEPTED));
            }
        };

        $client = new Hermesi(apiKey: self::KEY, baseUrl: 'https://hermesi.example', httpClient: $own);
        $client->events->trigger('order.shipped', 'user_1');

        self::assertCount(1, $own->seen);
        self::assertSame('https://hermesi.example/v1/events', (string) $own->seen[0]->getUri());
        self::assertSame('Bearer '.self::KEY, $own->seen[0]->getHeaderLine('Authorization'));
    }

    public function testRetriesAFailureThatAPsr18ClientReportsAsAnException(): void
    {
        $calls = 0;
        $flaky = new class($calls) implements ClientInterface {
            public function __construct(private int &$calls)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (1 === ++$this->calls) {
                    throw new class('connection reset') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
                        public function getRequest(): RequestInterface
                        {
                            return new \Nyholm\Psr7\Request('POST', 'https://hermesi.example');
                        }
                    };
                }

                return new Response(202, [], (string) json_encode(TestServer::ACCEPTED));
            }
        };

        $client = new Hermesi(apiKey: self::KEY, baseUrl: 'https://hermesi.example', httpClient: $flaky, sleep: static function (float $s): void {
        });
        $client->events->trigger('order.shipped', 'user_1');

        self::assertSame(2, $calls);
    }

    public function testRetriesAResponseWhoseBodyFailsWhileItIsBeingRead(): void
    {
        $calls = 0;
        $client = new class($calls) implements ClientInterface {
            public function __construct(private int &$calls)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (1 === ++$this->calls) {
                    // A lazy stream, as a streaming HTTP client returns: the status arrives, the body fails afterwards.
                    $broken = FnStream::decorate(Utils::streamFor('x'), [
                        '__toString' => static function (): string {
                            throw new \RuntimeException('connection reset while reading the body');
                        },
                    ]);

                    return new Response(202, [], $broken);
                }

                return new Response(202, [], (string) json_encode(TestServer::ACCEPTED));
            }
        };

        $result = (new Hermesi(apiKey: self::KEY, baseUrl: 'https://hermesi.example', httpClient: $client, sleep: static function (float $s): void {
        }))->events->trigger('order.shipped', 'user_1');

        self::assertSame(2, $calls);
        self::assertSame('evt_01K2QH8F3T7Y0RJ4N5V6WX8ZQD', $result->eventId);
    }

    public function testBuildsGuzzleWithATimeoutAndWithoutRedirectsWhenTheCallerLeavesTheChoiceToThePackage(): void
    {
        $client = HttpClients::client(7.0);

        self::assertInstanceOf(\GuzzleHttp\Client::class, $client);
        /** @var array<string, mixed> $config */
        $config = $client->getConfig();
        self::assertSame(7.0, $config['timeout']);
        self::assertSame(7.0, $config['connect_timeout']);
        self::assertFalse($config['allow_redirects']);
    }
}

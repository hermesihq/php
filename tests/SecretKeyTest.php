<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Exception\ConnectionException;
use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Hermesi\Tests\Support\TestServer;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/** The secret key is the one thing in this package that must never be printed, logged or cached. */
final class SecretKeyTest extends TestCase
{
    private const KEY = 'hm_sk_test_0123456789';

    private function client(): Hermesi
    {
        return new Hermesi(apiKey: self::KEY, baseUrl: 'https://hermesi.example', retry: new RetryPolicy(maxRetries: 0));
    }

    public function testNoWayOfPrintingTheClientShowsTheKey(): void
    {
        $hermesi = $this->client();

        ob_start();
        var_dump($hermesi);
        print_r($hermesi);
        $dumped = (string) ob_get_clean();
        $shown = [
            $dumped,
            var_export($hermesi, true),
            var_export($hermesi->events, true),
            (string) json_encode($hermesi),
            print_r((array) $hermesi, true),
            print_r((array) $hermesi->events, true),
        ];

        foreach ($shown as $text) {
            self::assertStringNotContainsString(self::KEY, $text);
            self::assertStringNotContainsString('0123456789', $text);
        }
    }

    public function testSymfonyVarDumperWhichIsWhatDdAndDumpAndLaravelErrorPagesUseDoesNotShowItEither(): void
    {
        // A client that has already made a request, so that the transport exists and holds the key too.
        $used = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(202, [], (string) json_encode(TestServer::ACCEPTED));
            }
        };
        $hermesi = new Hermesi(apiKey: self::KEY, baseUrl: 'https://hermesi.example', httpClient: $used, retry: new RetryPolicy(maxRetries: 0));
        $hermesi->events->trigger('order.shipped', 'user_1');

        $dumper = new CliDumper();
        $cloner = new VarCloner();
        foreach ([$hermesi, $hermesi->events, $hermesi->tokens] as $object) {
            $dumped = (string) $dumper->dump($cloner->cloneVar($object), true);
            self::assertStringNotContainsString(self::KEY, $dumped);
            self::assertStringNotContainsString('0123456789', $dumped);
        }
    }

    public function testTheClientCannotBeSerialisedSoItCannotLandInACacheOrASession(): void
    {
        foreach ([$this->client(), $this->client()->events] as $object) {
            try {
                serialize($object);
                self::fail('serialised');
            } catch (\Throwable $e) {
                self::assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
    }

    public function testAnExceptionFromARequestDoesNotCarryTheKeyInItsMessage(): void
    {
        $client = new Hermesi(apiKey: self::KEY, baseUrl: TestServer::deadUrl(), retry: new RetryPolicy(maxRetries: 0));

        try {
            $client->events->trigger('order.shipped', 'user_1');
            self::fail('no exception');
        } catch (ConnectionException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            $previous = $e->getPrevious();
            self::assertNotNull($previous);
            self::assertStringNotContainsString(self::KEY, $previous->getMessage());
        }
    }
}

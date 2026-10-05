<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Exception\ConnectionException;
use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Hermesi\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

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

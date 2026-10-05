<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Hermesi\Subscriber;
use Hermesi\Version;
use PHPUnit\Framework\TestCase;

final class SimulateAndConfigTest extends TestCase
{
    private const KEY = 'hm_sk_test_0123456789';

    protected function tearDown(): void
    {
        putenv('HERMESI_SECRET_KEY');
        putenv('HERMESI_BASE_URL');
        unset($_ENV['HERMESI_SECRET_KEY'], $_ENV['HERMESI_BASE_URL'], $_SERVER['HERMESI_SECRET_KEY'], $_SERVER['HERMESI_BASE_URL']);
    }

    public function testSimulateSendsNothingAndRecordsTheEventAsItWouldHaveGoneOut(): void
    {
        $test = new Hermesi(simulate: true);

        $result = $test->events->trigger(
            'order.shipped',
            new Subscriber('user_1', email: 'a@example.test'),
            ['order_id' => '4821', 'at' => new \DateTimeImmutable('2026-10-04T12:00:00+00:00')],
            idempotencyKey: 'k1',
            delay: '5m',
        );

        self::assertSame('simulated', $result->status);
        self::assertSame('k1', $result->idempotencyKey);
        self::assertFalse($result->replayed);
        self::assertTrue($test->isSimulating());
        self::assertCount(1, $test->simulated());
        $recorded = $test->simulated()[0];
        self::assertSame('order.shipped', $recorded->name);
        self::assertInstanceOf(Subscriber::class, $recorded->recipient);
        self::assertSame(['order_id' => '4821', 'at' => '2026-10-04T12:00:00.000+00:00'], $recorded->payload);
        self::assertSame('k1', $recorded->idempotencyKey);
        self::assertSame('5m', $recorded->body['delay']);
        self::assertSame(['external_id' => 'user_1', 'email' => 'a@example.test'], $recorded->body['recipient']);
    }

    public function testSimulateStillValidatesWhatARealCallWouldSoABadPayloadFailsInTheTestAndNotInProduction(): void
    {
        $test = new Hermesi(simulate: true);

        foreach ([
            static fn () => $test->events->trigger('order.shipped', 'user_1', ['n' => \NAN]),
            static fn () => $test->events->trigger('order.shipped', 'user_1', ['a', 'b']), // @phpstan-ignore argument.type
            static fn () => $test->events->trigger('', 'user_1'),
        ] as $call) {
            try {
                $call();
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $test->simulated());
    }

    public function testSimulateNumbersItsEventsMakesAPreferenceLinkAndMintsAToken(): void
    {
        $test = new Hermesi(simulate: true);

        self::assertSame('evt_simulated_1', $test->events->trigger('a.b', 'u')->eventId);
        self::assertSame('evt_simulated_2', $test->events->trigger('a.b', 'u')->eventId);
        self::assertSame('https://simulated.invalid/preferences/user%201', $test->subscribers->preferenceLink('user 1')->url);
        self::assertMatchesRegularExpression('/^[\w-]+\.[\w-]+$/', $test->tokens->mint('user_1', environmentId: 'env_1'));
    }

    public function testRequiresAKeyAndABaseUrl(): void
    {
        foreach ([
            [static fn () => new Hermesi(baseUrl: 'https://h.example'), 'apiKey is required'],
            [static fn () => new Hermesi(apiKey: self::KEY), 'baseUrl is required'],
        ] as [$make, $message]) {
            try {
                $make();
                self::fail('accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testReadsTheKeyAndTheBaseUrlFromTheEnvironment(): void
    {
        $server = Support\TestServer::start();
        try {
            putenv('HERMESI_SECRET_KEY='.self::KEY);
            putenv('HERMESI_BASE_URL='.$server->url().'/');

            (new Hermesi(retry: new RetryPolicy(maxRetries: 0)))->events->trigger('order.shipped', 'user_1');

            self::assertSame('/v1/events', $server->last()['path']);
            self::assertSame('Bearer '.self::KEY, $server->last()['headers']['authorization']);
        } finally {
            $server->stop();
        }
    }

    public function testRefusesAPublicKeyAndSaysWhy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/secret key.*public key/s');

        new Hermesi(apiKey: 'hm_pk_abc', baseUrl: 'https://h.example');
    }

    public function testRefusesABaseUrlThatIsNotHttpAndSettingsThatMakeNoSense(): void
    {
        foreach ([
            static fn () => new Hermesi(apiKey: self::KEY, baseUrl: 'hermesi.example'),
            static fn () => new Hermesi(apiKey: self::KEY, baseUrl: 'https://h.example', timeout: 0.0),
            static fn () => new Hermesi(apiKey: self::KEY, baseUrl: 'https://h.example', timeout: \NAN),
        ] as $make) {
            try {
                $make();
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheVersionIsTheOneAtTheTopOfTheChangelog(): void
    {
        $changelog = (string) file_get_contents(__DIR__.'/../CHANGELOG.md');

        self::assertSame(1, preg_match('/^## (\d+\.\d+\.\d+)/m', $changelog, $match));
        self::assertSame($match[1] ?? '', Version::VERSION);
    }
}

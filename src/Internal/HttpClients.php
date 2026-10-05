<?php

declare(strict_types=1);

namespace Hermesi\Internal;

use Hermesi\Exception\HermesiException;
use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Which HTTP client to use when the caller did not pass one.
 *
 * PSR-18 has no way to ask a client for a timeout: it belongs to the client, set when it was built. And the default of the
 * clients people have is no timeout at all (Guzzle: none), which in a web request is a worker held until the web server gives up. So
 * when the caller leaves the choice to this package and Guzzle or Symfony's HttpClient is installed, the client is built here
 * with a timeout and without redirects (a redirect would turn the POST into a GET and lose the event). Anything else goes through
 * `php-http/discovery` as it comes, and then the timeout is the caller's to set.
 *
 * @internal
 */
final class HttpClients
{
    public static function client(float $timeout): ClientInterface
    {
        $guzzle = 'GuzzleHttp\Client';
        if (class_exists($guzzle) && is_subclass_of($guzzle, ClientInterface::class)) {
            /** @var ClientInterface */
            return new $guzzle([
                'timeout' => $timeout,
                'connect_timeout' => min(10.0, $timeout),
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        }

        $symfony = 'Symfony\Component\HttpClient\Psr18Client';
        $factory = 'Symfony\Component\HttpClient\HttpClient';
        if (class_exists($symfony) && class_exists($factory) && is_subclass_of($symfony, ClientInterface::class)) {
            /** @var ClientInterface */
            return new $symfony($factory::create(['timeout' => $timeout, 'max_redirects' => 0]));
        }

        try {
            return Psr18ClientDiscovery::find();
        } catch (NotFoundException $e) {
            throw new HermesiException(
                'No PSR-18 HTTP client is installed. Install one, for example: composer require guzzlehttp/guzzle '
                .'(or symfony/http-client nyholm/psr7), or pass your own client as the httpClient argument.',
                0,
                $e,
            );
        }
    }

    public static function requestFactory(): RequestFactoryInterface
    {
        try {
            return Psr17FactoryDiscovery::findRequestFactory();
        } catch (NotFoundException $e) {
            throw new HermesiException(self::factoryHelp(), 0, $e);
        }
    }

    public static function streamFactory(): StreamFactoryInterface
    {
        try {
            return Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException $e) {
            throw new HermesiException(self::factoryHelp(), 0, $e);
        }
    }

    private static function factoryHelp(): string
    {
        return 'No PSR-17 request and stream factory is installed. Guzzle brings one (guzzlehttp/psr7); otherwise install one, '
            .'for example: composer require nyholm/psr7, or pass your own as the requestFactory and streamFactory arguments.';
    }
}

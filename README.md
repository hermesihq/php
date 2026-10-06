# hermesihq/hermesi

Server-side client for [Hermesi](https://github.com/hermesihq): publish events, mint subscriber tokens and preference links.
Thin on purpose: it builds the request, sends it with retries, and turns the answer into a typed result or an exception. It makes no
decision about notifications; that is the platform's job.

- **Works with any HTTP client.** It talks [PSR-18](https://www.php-fig.org/psr/psr-18/) and finds the client you already have
  through `php-http/discovery`: Guzzle, Symfony HttpClient, or your own. Nothing else is required.
- **Retries** on connection failures, timeouts, `429` (honouring `Retry-After`) and `5xx`, with exponential backoff and jitter.
- **Idempotency built in.** Every event goes out with an `Idempotency-Key` (generated if you give none) that is kept across the
  retries, so a lost response cannot send a notification twice.
- **Strict about your data.** A payload JSON would silently corrupt is refused before anything is sent (see below).
- **A test mode** that sends nothing and records what you would have sent.
- PHP 8.1 and later. CI runs 8.1 to 8.5.

For Laravel, use the wrapper, [`hermesihq/laravel`](https://github.com/hermesihq/laravel): it adds the facade, a test fake and a queued job on top
of this package.

## Install

```
composer require hermesihq/hermesi
```

It needs a PSR-18 HTTP client and finds the one you have. If your application has none, Composer asks (through the
`php-http/discovery` plugin) whether it may install one, and installs Symfony's HttpClient. To choose another:

```
composer require guzzlehttp/guzzle
```

If the plugin is disabled and no client is installed, the first request fails with a message that says what to install.

## Publish an event

```php
use Hermesi\Hermesi;

$hermesi = new Hermesi(
    apiKey: getenv('HERMESI_SECRET_KEY'), // hm_sk_..., your SECRET key, server side only
    baseUrl: 'https://your-hermesi-host',
);

$result = $hermesi->events->trigger(
    'order.shipped',                       // <noun>.<past-tense-verb>
    'user_8821',                           // a subscriber's external id
    ['order_id' => '4821', 'tracking_url' => $url],
    idempotencyKey: "order-{$order->id}-shipped", // see below
);
echo $result->eventId, ' ', $result->status; // evt_..., accepted
```

`202` means the event is recorded and queued. Nothing has been delivered yet: watch the Activity Log in the dashboard.

`$recipient` is a subscriber's external id, a `Subscriber` described inline (created or updated on the fly), or a list of up to 100 of
either:

```php
use Hermesi\Subscriber;

$hermesi->events->trigger('order.shipped', new Subscriber('cust_331', email: 'a@example.cm', locale: 'fr'), ['order_id' => '4821']);
```

The client reads `HERMESI_SECRET_KEY` and `HERMESI_BASE_URL` from the environment if you do not pass them.

### Idempotency: pass your own key when your code can run twice

The SDK generates a key per call and reuses it across its own retries, which covers a lost response. It cannot cover **your** code
running twice for the same thing (a webhook handler that is retried, a queue job that is redelivered): the second run generates a new
key. For that, give the event a key derived from what happened, such as `order-4821-shipped`. Replaying a key with the same body within
24 hours returns the original answer, and `$result->replayed` is `true`.

### Other arguments

`actor: new Actor(externalId: ..., name: ...)`, `delay: 'PT15M'`, `sendAt: new DateTimeImmutable(...)`, `override: [...]`,
`tenant: '...'`. They are named arguments, so you pass only what you need.

### Scheduling

`delay` holds the event back for an ISO 8601 duration (`PT15M`, `PT1H30M`, `P1D`: **not** `15m`) and `sendAt` until an instant
(a `DateTimeInterface`, which always carries its offset, or an ISO 8601 string with one). Give one, not both, at most 30 days ahead. A time already past runs at once. The run starts within about a minute after
its time, not at the second. A request the server cannot honour is refused with `422 invalid_schedule`: it is never sent
immediately instead. Pass your own idempotency key and retrying a scheduled event does not schedule it twice.

`override` and `tenant` are accepted by the API but not acted on yet.

### Your data, and what PHP's `json_encode` does to it

A notification payload is where PHP's own JSON encoding hurts, so this package does not use it as it is:

- **An empty payload is `{}`, not `[]`.** `json_encode([])` is `[]`, which the API refuses. An empty `payload`, `override` or `data` is
  sent as an object.
- **A list where an object is needed is refused**, with the name of the argument: `trigger('x.y', 'u', ['a', 'b'])` throws
  `payload must be an associative array (a JSON object), not a list`, instead of an API error that blames the server.
- `DateTimeInterface` is sent as ISO 8601 with its offset; a backed enum as its value; a `Stringable` as its string;
  `JsonSerializable` is honoured.
- **Refused before anything is sent**, naming the path (`payload.order.total`): `NAN` and `INF`, a closure, a resource, an enum without a
  value, an object that is none of the above, a string that is not UTF-8, and anything nested more than 64 levels (a cycle).

## Subscriber tokens

A browser or an app talks to Hermesi's client API as one subscriber, with a token minted on **your** server:

```php
$token = $hermesi->tokens->mint('user_8821', environmentId: 'env_01...'); // valid for an hour at most
// hand it to your frontend, which sends it with the public key
```

`environmentId` is the `env_...` id shown in the dashboard; the key itself does not carry it. Minting makes no request. Also available on its
own: `Hermesi\SubscriberToken::mint($secretKey, $externalId, $environmentId)`.

## Preference links

```php
$link = $hermesi->subscribers->preferenceLink('user_8821');
$link->url; // a hosted page that needs no login and works for about a year
```

## Errors

```php
use Hermesi\Exception\ApiException;
use Hermesi\Exception\ConnectionException;
use Hermesi\Exception\NotFoundException;
use Hermesi\Exception\RateLimitException;

try {
    $hermesi->events->trigger('order.shipped', 'user_8821', ['order_id' => $id]);
} catch (NotFoundException) {
    // that recipient is not a subscriber in this environment
} catch (RateLimitException $e) {
    $e->retryAfter; // seconds the server asked to wait (it was too long to wait out)
} catch (ApiException $e) {
    $e->errorCode; $e->requestId; // branch on errorCode; quote requestId to whoever runs Hermesi
} catch (ConnectionException) {
    // could not reach Hermesi, after the retries
}
```

`AuthenticationException` (401), `ForbiddenException` (403), `NotFoundException` (404), `ValidationException` (400, 422; see `->details`),
`RateLimitException` (429) and `ServerException` (5xx) all extend `ApiException`, which extends `HermesiException`, as does
`ConnectionException`. Branch on the class or on `errorCode`, never on the message. (`getCode()` is PHP's own integer and stays `0`;
the API's code is a string, so it is `errorCode`.) A response that is not the API's error envelope has `errorType === 'sdk_error'` and
`errorCode === 'unexpected_response'`, which the API never sends.

A mistake in how you call it (a public key, a missing name, a payload that cannot be sent) is a plain `\InvalidArgumentException`, not a
`HermesiException`: it is a bug to fix, not a failure to handle.

### Retries

```php
use Hermesi\RetryPolicy;

new Hermesi(retry: new RetryPolicy(maxRetries: 3, baseDelay: 0.5, maxDelay: 8.0, maxRetryAfter: 30.0)); // these are the defaults
new Hermesi(retry: new RetryPolicy(maxRetries: 0)); // no retrying
```

Times are in seconds. A `Retry-After` is waited out exactly, up to `maxRetryAfter`; a server that asks for more gets its exception thrown,
because a request that sleeps for ten minutes is worse than one that fails.

### Timeouts, and the HTTP client you pass

PSR-18 has no way to ask a client for a timeout: it belongs to the client. So:

- **If you do not pass a client** and Guzzle or Symfony's HttpClient is installed, the package builds it with the `timeout` argument
  (default 30 seconds) and without redirects.
- **If you pass your own** (`httpClient: $client`), its timeout is yours to set, and so is turning redirects off: a redirect would turn the
  POST into a GET and lose the event. This package treats any `3xx` as an error either way.
- Guzzle's own default is **no timeout at all**, which in a web request holds a worker until the web server gives up. If you build the Guzzle
  client yourself, set `timeout`.

## Testing your code

```php
$hermesi = new Hermesi(simulate: true); // no key, no URL, no network
$hermesi->events->trigger('order.shipped', 'user_1', ['order_id' => '4821']);

$hermesi->simulated()[0]->name;    // 'order.shipped'
$hermesi->simulated()[0]->payload; // ['order_id' => '4821'], as it would have been sent
```

A simulated call validates and serialises exactly as a real one does, so a payload that would fail in production fails in your test. It
does not know your workflows: it cannot tell you whether an event matches one.

## The key never shows

The client holds your secret key, so it is built not to leak it: `var_dump`, `print_r`, `var_export`, `json_encode` and `(array)` do not
show it, and the client cannot be serialised (so it cannot end up in a cache or a session). On PHP 8.2 and later the key is also redacted from
stack traces. On 8.1, leave `zend.exception_ignore_args` on in production, which is the default of the production `php.ini`.

## Not included

There is no method for messages or subscribers beyond the preference link, because Hermesi's secret-key API does not have them yet: those
are the dashboard's (Management) API. Outbound webhooks are not implemented in Hermesi yet either, so there is nothing to verify.

## Development

```
composer install
composer test    # PHPUnit; talks to a real HTTP server on localhost, not a fake client
composer stan    # PHPStan, max level
composer cs      # php-cs-fixer, dry run
```

`tests/LiveTest.php` runs the SDK against a real Hermesi (`vendor/bin/phpunit --group live`) and is skipped unless you point it at one; read its
header.

## License

MIT

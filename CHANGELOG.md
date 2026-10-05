# Changelog

All notable changes to `hermesihq/hermesi`. This file describes what a consumer gets.

**`0.x` means the public API can still change.** A minor bump may contain a breaking change; a patch bump will not. Each release
lists breaking changes first.

## Unreleased

### Fixed

- **The documentation showed `delay: '15m'`**, a format the server does not accept: `delay` is an ISO 8601 duration, `'PT15M'`.
  It also did not say that the API ignored `sendAt` and `delay` until now; Hermesi now honours them (or refuses a request it
  cannot honour with `422 invalid_schedule`). The README has a Scheduling section.

## 0.1.0 (2026-10-05)

First release.

### Added

- `new Hermesi(apiKey: ..., baseUrl: ...)` with `events->trigger(...)`, `subscribers->preferenceLink(...)` and `tokens->mint(...)`.
- Any PSR-18 HTTP client, found through `php-http/discovery` or passed in. Guzzle and Symfony's HttpClient are built with a timeout
  and without redirects when you leave the choice to the package.
- Retries on connection failures, timeouts, `429` (honouring `Retry-After`) and `5xx`, with backoff and jitter.
- An idempotency key on every event, generated if you give none and kept across retries.
- Payloads PHP's `json_encode` would corrupt are handled or refused before anything is sent: an empty array is `{}`, a list where an
  object is needed is an error, `NAN`, closures, resources, invalid UTF-8 and cycles are refused with the path to the value.
- `simulate: true`, which records events instead of sending them.
- Typed exceptions: `ApiException` and its subclasses by status, `ConnectionException`.
- The secret key never shows in `var_dump`, `print_r`, `var_export`, `json_encode` or `(array)`, and the client cannot be serialised.

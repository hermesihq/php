# Changelog

All notable changes to `hermesihq/hermesi`. This file describes what a consumer gets.

**`0.x` means the public API can still change.** A minor bump may contain a breaking change; a patch bump will not. Each release
lists breaking changes first.

## 0.3.0 (2026-10-08)

### Added

- **`subscribers->bulk($rows)`**: create or update up to 1 000 subscribers in one request, for a first import or a nightly sync. Each row
  is an array with an `external_id` and any `put` key, with the same meaning (a key you include is set, `null` clears it, a key you leave
  out is left alone). A key that is none of those is an `\InvalidArgumentException` naming the row. All or nothing: a server refusal
  lists every problem with its row and writes nothing. `BulkSubscribersResult` and `BulkSubscriberResult` say which rows were created and
  which updated.

## 0.2.0 (2026-10-07)

### Added

- **`events->get($eventId)`**: the notification each recipient got from an event and the messages each produced, with how far each
  got. `EventRun`, `RunNotification`, `Message` (`isFinal` tells when nothing more will happen).
- **Subscribers**: `subscribers->put`, `patch`, `get`, `delete`, `registerChannel`, `removeChannel`, `preferences` and
  `updatePreferences`. A key you give is set, `null` clears the field and a key you leave out is left alone; `data` replaces. An unknown
  key is an `\InvalidArgumentException`. `SubscriberProfile`, `ChannelIdentity`, `Preferences`.
- **`messages->send` and `messages->get`**: the direct send, for when the channel is a requirement (an OTP that must be an SMS). It keeps
  one idempotency key across its retries, generated if you give none. `MessageResult`.
- `ConflictException` for a `409` (it used to be a bare `ApiException`), `SimulationException`, `simulatedCalls()` and `SimulatedCall`
  for test mode. In test mode reads throw rather than invent an answer.

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

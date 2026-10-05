<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Exception\ApiException;
use Hermesi\Internal\Core;
use Hermesi\Internal\Json;

final class Events
{
    /** @internal */
    public function __construct(private readonly Core $core)
    {
    }

    /**
     * Tell Hermesi that something happened. `202`: the event is recorded and queued; nothing is delivered yet, so watch the
     * Activity Log in the dashboard.
     *
     * `$recipient` is a subscriber's `externalId`, a {@see Subscriber} (created or updated on the fly), or a list of up to 100 of
     * either. `$payload` must be an associative array: it is the object your templates read.
     *
     * `$idempotencyKey` is generated if you give none, and reused across retries. **Pass your own** when your code might run
     * twice for the same thing (a webhook handler, a queue consumer): only your key survives that.
     *
     * @param string|Subscriber|list<string|Subscriber> $recipient
     * @param array<string, mixed>                      $payload
     * @param array<string, mixed>|null                 $override
     */
    public function trigger(
        string $name,
        string|Subscriber|array $recipient,
        array $payload = [],
        ?string $idempotencyKey = null,
        ?Actor $actor = null,
        ?string $delay = null,
        \DateTimeInterface|string|null $sendAt = null,
        ?array $override = null,
        ?string $tenant = null,
    ): EventResult {
        if ('' === $name) {
            throw new \InvalidArgumentException('name is required, for example order.shipped');
        }
        $key = null === $idempotencyKey || '' === $idempotencyKey ? self::uuid() : $idempotencyKey;

        $body = [
            'name' => $name,
            'recipient' => self::recipient($recipient),
            'payload' => Json::object($payload, 'payload'),
        ];
        if (null !== $actor) {
            $wire = [];
            if (null !== $actor->externalId) {
                $wire['external_id'] = $actor->externalId;
            }
            if (null !== $actor->name) {
                $wire['name'] = $actor->name;
            }
            $body['actor'] = [] === $wire ? new \stdClass() : $wire;
        }
        if (null !== $delay) {
            $body['delay'] = $delay;
        }
        if (null !== $sendAt) {
            $body['send_at'] = $sendAt instanceof \DateTimeInterface ? $sendAt->format(\DATE_RFC3339_EXTENDED) : $sendAt;
        }
        if (null !== $override) {
            $body['override'] = Json::object($override, 'override');
        }
        if (null !== $tenant) {
            $body['tenant'] = $tenant;
        }

        // Encoded before the simulate branch: a payload that cannot be serialised must fail in a test exactly as it would in
        // production, or simulate mode would hide the bug it exists to catch.
        $encoded = Json::encode($body);

        if ($this->core->simulate) {
            /** @var array<string, mixed> $sent */
            $sent = json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);

            return $this->core->simulateEvent($name, $recipient, $key, $sent);
        }

        $answer = $this->core->transport()->send('POST', '/v1/events', $encoded, $key);
        $decoded = $answer->json();
        if (!\is_array($decoded) || !isset($decoded['event_id']) || !\is_string($decoded['event_id'])) {
            throw ApiException::fromResponse($answer->status, null, null);
        }

        return EventResult::fromWire($decoded, $answer->replayed, $key);
    }

    /**
     * @param string|Subscriber|list<string|Subscriber> $recipient
     */
    private static function recipient(string|Subscriber|array $recipient): mixed
    {
        if (\is_array($recipient)) {
            if (!array_is_list($recipient)) {
                throw new \InvalidArgumentException('recipient must be an externalId, a Subscriber, or a list of them');
            }

            return array_map(static fn (string|Subscriber $each): mixed => self::recipient($each), $recipient);
        }
        if (\is_string($recipient)) {
            return $recipient;
        }
        $wire = ['external_id' => $recipient->externalId];
        foreach (['email' => $recipient->email, 'phone_e164' => $recipient->phoneE164, 'name' => $recipient->name, 'locale' => $recipient->locale] as $field => $value) {
            if (null !== $value) {
                $wire[$field] = $value;
            }
        }
        if (null !== $recipient->data) {
            $wire['data'] = Json::object($recipient->data, 'recipient.data');
        }

        return $wire;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

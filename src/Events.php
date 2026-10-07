<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Exception\ApiException;
use Hermesi\Internal\Answer;
use Hermesi\Internal\Core;
use Hermesi\Internal\Json;
use Hermesi\Internal\Path;
use Hermesi\Internal\Recipients;
use Hermesi\Internal\Uuid;

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
        $key = null === $idempotencyKey || '' === $idempotencyKey ? Uuid::v4() : $idempotencyKey;

        $body = [
            'name' => $name,
            'recipient' => Recipients::wire($recipient),
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
     * What became of an event: the notification each recipient got, the messages each produced and how far each got. A message's
     * status moves on after the event was accepted, so poll it (`$message->isFinal`) rather than treating the first answer as
     * final. It never returns what was sent or the recipient's address; an event of another environment is a NotFoundException.
     */
    public function get(string $eventId): EventRun
    {
        return $this->core->call(
            'GET',
            '/v1/events/'.Path::segment($eventId, 'eventId'),
            null,
            null,
            null,
            static fn (Answer $answer): EventRun => EventRun::fromWire(Core::object($answer, 'event_id')),
        );
    }
}

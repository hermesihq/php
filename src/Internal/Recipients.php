<?php

declare(strict_types=1);

namespace Hermesi\Internal;

use Hermesi\Subscriber;

/** @internal */
final class Recipients
{
    /**
     * A recipient as it goes on the wire: an externalId, a subscriber described inline, or a list of them.
     *
     * @param string|Subscriber|list<string|Subscriber> $recipient
     */
    public static function wire(string|Subscriber|array $recipient): mixed
    {
        if (\is_array($recipient)) {
            if (!array_is_list($recipient)) {
                throw new \InvalidArgumentException('recipient must be an externalId, a Subscriber, or a list of them');
            }

            return array_map(static fn (string|Subscriber $each): mixed => self::wire($each), $recipient);
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
}

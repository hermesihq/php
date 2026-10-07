<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Exception\ApiException;
use Hermesi\Internal\Answer;
use Hermesi\Internal\Core;
use Hermesi\Internal\Json;
use Hermesi\Internal\Path;

final class Subscribers
{
    private const PROFILE_FIELDS = ['email', 'phone_e164', 'first_name', 'last_name', 'locale', 'timezone', 'avatar_url', 'data'];

    /** @internal */
    public function __construct(private readonly Core $core)
    {
    }

    /**
     * Create the subscriber, or update it: **a key you give is set, `null` clears the field, and a key you leave out is left
     * alone**, so a sync job that knows half a profile does not blank the other half. `data` **replaces** the stored attributes
     * (at most 32 KB) rather than merging. The server checks the shapes (an email looks like one, `phone_e164` is E.164, `locale` a
     * language tag, `timezone` an IANA name) and refuses what it does not know, as a ValidationException naming the field.
     *
     * A key this method does not know (`phoneE164` instead of `phone_e164`) is an \InvalidArgumentException rather than a value
     * silently dropped.
     *
     * @param array{email?: string|null, phone_e164?: string|null, first_name?: string|null, last_name?: string|null, locale?: string|null, timezone?: string|null, avatar_url?: string|null, data?: array<string, mixed>|null} $fields
     */
    public function put(string $externalId, array $fields = []): SubscriberProfile
    {
        return $this->write('PUT', $externalId, $fields);
    }

    /**
     * Like {@see self::put()}, but a NotFoundException if the subscriber does not exist, instead of creating it.
     *
     * @param array{email?: string|null, phone_e164?: string|null, first_name?: string|null, last_name?: string|null, locale?: string|null, timezone?: string|null, avatar_url?: string|null, data?: array<string, mixed>|null} $fields
     */
    public function patch(string $externalId, array $fields = []): SubscriberProfile
    {
        return $this->write('PATCH', $externalId, $fields);
    }

    /** The profile, the channel identities and the stored preference overrides. A NotFoundException for an unknown or erased subscriber. */
    public function get(string $externalId): SubscriberProfile
    {
        return $this->core->call(
            'GET',
            '/v1/subscribers/'.Path::segment($externalId),
            null,
            null,
            null,
            static fn (Answer $answer): SubscriberProfile => SubscriberProfile::fromWire(Core::object($answer, 'external_id')),
        );
    }

    /**
     * Erase the personal data (email, phone, names, attributes, channel identities, preferences) and replace the address on every
     * message the person received by `[deleted]`, keeping the messages and their status for your statistics. Idempotent: deleting
     * twice, or an unknown subscriber, is not an error. Inbox items, the stored text of messages and event payloads are **not**
     * erased yet.
     */
    public function delete(string $externalId): void
    {
        $this->core->call(
            'DELETE',
            '/v1/subscribers/'.Path::segment($externalId),
            null,
            null,
            static fn (int $n, array $sent) => null,
            static fn (Answer $answer) => null,
        );
    }

    /**
     * Register where to reach the subscriber on a channel: a device token, a chat id, a Web Push endpoint. Safe to call on every
     * app start: it refreshes the identity and makes it active again if a provider had marked it invalid, and never duplicates it.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function registerChannel(string $externalId, string $channel, string $identifier, ?array $metadata = null): ChannelIdentity
    {
        $path = '/v1/subscribers/'.Path::segment($externalId).'/channels';
        if ('' === $channel) {
            throw new \InvalidArgumentException('channel is required, for example push');
        }
        if ('' === $identifier) {
            throw new \InvalidArgumentException('identifier is required: a device token, a chat id or a Web Push endpoint');
        }
        $body = ['channel' => Json::normalize($channel, 'channel'), 'identifier' => Json::normalize($identifier, 'identifier')];
        if (null !== $metadata) {
            $body['metadata'] = Json::object($metadata, 'metadata');
        }

        return $this->core->call(
            'POST',
            $path,
            $body,
            null,
            static fn (int $n, array $sent): ChannelIdentity => ChannelIdentity::fromWire($sent),
            static fn (Answer $answer): ChannelIdentity => ChannelIdentity::fromWire(Core::object($answer, 'identifier')),
        );
    }

    /** Forget a destination. Idempotent. */
    public function removeChannel(string $externalId, string $channel, string $identifier): void
    {
        // `identifier` is the rest of the path on the server, so a Web Push endpoint URL goes in percent-encoded as one segment.
        $path = '/v1/subscribers/'.Path::segment($externalId).'/channels/'.Path::segment($channel, 'channel').'/'.Path::segment($identifier, 'identifier');
        $this->core->call('DELETE', $path, null, null, static fn (int $n, array $sent) => null, static fn (Answer $answer) => null);
    }

    /** The stored overrides. A channel or category that is absent has none and follows the category's default. */
    public function preferences(string $externalId): Preferences
    {
        return $this->core->call(
            'GET',
            '/v1/subscribers/'.Path::segment($externalId).'/preferences',
            null,
            null,
            null,
            static fn (Answer $answer): Preferences => Preferences::fromWire(Core::object($answer, 'global')),
        );
    }

    /**
     * Change overrides: `true` or `false` sets one, `null` removes it so the category's default applies again, and what you
     * leave out is untouched. All or nothing: an unknown category (NotFoundException) or a critical one (ValidationException)
     * refuses the whole update.
     *
     *     $hermesi->subscribers->updatePreferences('user_8821', global: ['sms' => false], categories: ['marketing' => ['email' => false, 'push' => null]]);
     *
     * @param array<string, bool|null>|null                $global
     * @param array<string, array<string, bool|null>>|null $categories
     */
    public function updatePreferences(string $externalId, ?array $global = null, ?array $categories = null): Preferences
    {
        $path = '/v1/subscribers/'.Path::segment($externalId).'/preferences';
        $body = [];
        if (null !== $global) {
            $body['global'] = self::flags($global, 'global');
        }
        if (null !== $categories) {
            $wire = [];
            foreach ($categories as $category => $channels) {
                if (!\is_array($channels)) {
                    throw new \InvalidArgumentException(\sprintf('categories.%s must be an array of channel => true|false|null', $category));
                }
                $wire[(string) $category] = self::flags($channels, 'categories.'.$category);
            }
            $body['categories'] = [] === $wire ? new \stdClass() : $wire;
        }
        if ([] === $body) {
            throw new \InvalidArgumentException('give $global or $categories: there is nothing to change');
        }

        return $this->core->call(
            'PATCH',
            $path,
            $body,
            null,
            static function (int $n, array $sent): Preferences {
                $kept = static fn (mixed $flags): array => array_filter(\is_array($flags) ? $flags : [], static fn (mixed $flag): bool => null !== $flag);
                $categories = [];
                foreach (\is_array($sent['categories'] ?? null) ? $sent['categories'] : [] as $category => $channels) {
                    $categories[(string) $category] = $kept($channels);
                }

                return Preferences::fromWire(['global' => $kept($sent['global'] ?? []), 'categories' => $categories]);
            },
            static fn (Answer $answer): Preferences => Preferences::fromWire(Core::object($answer, 'global')),
        );
    }

    /** A link to the hosted preference page for one subscriber. It needs no login and works for about a year. */
    public function preferenceLink(string $externalId): PreferenceLink
    {
        $segment = Path::segment($externalId);
        if ($this->core->simulate) {
            return new PreferenceLink('https://simulated.invalid/preferences/'.$segment);
        }
        $answer = $this->core->transport()->send('POST', '/v1/subscribers/'.$segment.'/preference-link', '{}', null);
        $body = $answer->json();
        if (!\is_array($body) || !isset($body['url']) || !\is_string($body['url'])) {
            throw ApiException::fromResponse($answer->status, null, null);
        }

        return new PreferenceLink($body['url']);
    }

    /**
     * @param array<mixed> $fields
     */
    private function write(string $method, string $externalId, array $fields): SubscriberProfile
    {
        $path = '/v1/subscribers/'.Path::segment($externalId);
        $body = [];
        foreach ($fields as $name => $value) {
            if (!\in_array($name, self::PROFILE_FIELDS, true)) {
                throw new \InvalidArgumentException(\sprintf('unknown subscriber field "%s"; expected %s', $name, implode(', ', self::PROFILE_FIELDS)));
            }
            if ('data' === $name) {
                if (null !== $value && !\is_array($value)) {
                    throw new \InvalidArgumentException('data must be an associative array, or null to clear it');
                }
                $body['data'] = null === $value ? null : Json::object($value, 'data');
                continue;
            }
            if (null !== $value && !\is_string($value)) {
                throw new \InvalidArgumentException(\sprintf('%s must be a string, or null to clear it, got %s', $name, get_debug_type($value)));
            }
            $body[$name] = null === $value ? null : Json::normalize($value, $name);
        }

        return $this->core->call(
            $method,
            $path,
            [] === $body ? new \stdClass() : $body,
            null,
            static fn (int $n, array $sent): SubscriberProfile => SubscriberProfile::fromWire(['id' => 'sub_simulated_'.$n, 'external_id' => $externalId] + $sent),
            static fn (Answer $answer): SubscriberProfile => SubscriberProfile::fromWire(Core::object($answer, 'external_id')),
        );
    }

    /**
     * @param array<mixed> $flags
     *
     * @return array<string, bool|null>|\stdClass
     */
    private static function flags(array $flags, string $path): array|\stdClass
    {
        $out = [];
        foreach ($flags as $channel => $flag) {
            if (null !== $flag && !\is_bool($flag)) {
                throw new \InvalidArgumentException(\sprintf('%s.%s must be true, false or null, got %s', $path, $channel, get_debug_type($flag)));
            }
            $out[(string) $channel] = $flag;
        }

        return [] === $out ? new \stdClass() : $out;
    }
}

<?php

declare(strict_types=1);

namespace Hermesi\Internal;

/**
 * The JSON an event is sent as.
 *
 * PHP's own `json_encode` loses or distorts data without a word, and a notification payload is where it hurts: an empty array
 * is `[]` where the API needs `{}`; a list sent where an object is expected is a type error the API words as the server's
 * fault; `NAN` and a resource are errors or `0`; an object is whatever its public properties happen to be. So the values that
 * cannot be sent faithfully are refused here, with the path to the offending one, and the ones with one obvious form (a date, a
 * backed enum, a Stringable) are given it.
 *
 * @internal
 */
final class Json
{
    private const MAX_DEPTH = 64;

    /**
     * An associative array as the JSON object it must be. An empty array becomes `{}`; a list is refused, because the API would
     * read it as an array and refuse it with a message that blames the wrong thing.
     *
     * @param array<mixed> $value
     *
     * @return \stdClass|array<mixed>
     */
    public static function object(array $value, string $path): \stdClass|array
    {
        if ([] === $value) {
            return new \stdClass();
        }
        if (array_is_list($value)) {
            throw new \InvalidArgumentException(\sprintf('%s must be an associative array (a JSON object), not a list', $path));
        }

        return self::normalizeArray($value, $path, 0);
    }

    /**
     * @param array<mixed>|\stdClass $body the request body: every key is already a JSON object key
     */
    public static function encode(array|\stdClass $body): string
    {
        try {
            return json_encode(
                $body,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The request could not be encoded as JSON: '.$e->getMessage(), 0, $e);
        }
    }

    public static function normalize(mixed $value, string $path, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(\sprintf('%s is nested more than %d levels deep (a cycle?)', $path, self::MAX_DEPTH));
        }

        return match (true) {
            null === $value, \is_bool($value), \is_int($value) => $value,
            \is_float($value) => is_finite($value) ? $value : throw new \InvalidArgumentException(\sprintf('%s is %s, which JSON cannot hold', $path, is_nan($value) ? 'NAN' : 'INF')),
            \is_string($value) => 1 === preg_match('//u', $value) ? $value : throw new \InvalidArgumentException(\sprintf('%s is not valid UTF-8', $path)),
            \is_array($value) => self::normalizeArray($value, $path, $depth),
            $value instanceof \DateTimeInterface => $value->format(\DATE_RFC3339_EXTENDED),
            $value instanceof \BackedEnum => self::normalize($value->value, $path, $depth + 1),
            $value instanceof \UnitEnum => throw new \InvalidArgumentException(\sprintf('%s is an enum without a value (%s); use a backed enum', $path, $value::class)),
            $value instanceof \JsonSerializable => self::normalize($value->jsonSerialize(), $path, $depth + 1),
            $value instanceof \Stringable => (string) $value,
            $value instanceof \stdClass => self::normalizeObject($value, $path, $depth),
            \is_object($value) => throw new \InvalidArgumentException(\sprintf('%s is a %s, which cannot be sent: convert it to an array or implement JsonSerializable', $path, $value::class)),
            default => throw new \InvalidArgumentException(\sprintf('%s is a %s, which cannot be sent', $path, get_debug_type($value))),
        };
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function normalizeArray(array $value, string $path, int $depth): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::normalize($item, $path.'.'.$key, $depth + 1);
        }

        return $out;
    }

    private static function normalizeObject(\stdClass $value, string $path, int $depth): \stdClass
    {
        $out = new \stdClass();
        foreach (get_object_vars($value) as $key => $item) {
            $out->{(string) $key} = self::normalize($item, $path.'.'.$key, $depth + 1);
        }

        return $out;
    }
}

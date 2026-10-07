<?php

declare(strict_types=1);

namespace Hermesi\Internal;

/**
 * Reading what the server sent, leniently: a field of the wrong type becomes an empty one rather than a crash, because the
 * required field is checked once, where the result is built.
 *
 * @internal
 */
final class Wire
{
    public static function text(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    public static function nullableText(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        /** @var array<string, mixed> $map */
        $map = \is_array($value) ? $value : [];

        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function nullableMap(mixed $value): ?array
    {
        return \is_array($value) ? self::map($value) : null;
    }

    /**
     * The array items that are themselves objects.
     *
     * @return list<array<string, mixed>>
     */
    public static function records(mixed $value): array
    {
        $out = [];
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (\is_array($item)) {
                    $out[] = self::map($item);
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, bool>
     */
    public static function flags(mixed $value): array
    {
        $out = [];
        if (\is_array($value)) {
            foreach ($value as $key => $flag) {
                $out[(string) $key] = (bool) $flag;
            }
        }

        return $out;
    }
}

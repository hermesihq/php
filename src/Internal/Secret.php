<?php

declare(strict_types=1);

namespace Hermesi\Internal;

/**
 * Holds the secret key so that no dump of any object that references it can print it.
 *
 * A property is printed by `var_dump`, `print_r`, `var_export`, `(array)` and Symfony's VarDumper (which is what `dd()`, `dump()` and
 * Laravel's error pages use). A closure that captured the key is printed by VarDumper too, with its `use` variables. A static map is
 * printed by none of them: they show an object's own properties, and the value is not one. So the key lives in a static `WeakMap`
 * keyed by this object, and this object has no properties at all. When the object goes, so does the entry.
 *
 * @internal
 */
final class Secret
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    public function __construct(
        #[\SensitiveParameter]
        string $value,
    ) {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? '';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('The Hermesi secret key cannot be serialised.');
    }

    /** A clone would be an object the map does not know, and silently a blank key. */
    private function __clone()
    {
    }
}

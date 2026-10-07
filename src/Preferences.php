<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Wire;

/** The overrides a subscriber has stored. A channel or category that is absent has none and follows the category's default. */
final class Preferences
{
    /**
     * @param array<string, bool>                $global     per channel, for every category: `['sms' => false]`
     * @param array<string, array<string, bool>> $categories per category key, then per channel: `['marketing' => ['email' => false]]`
     */
    public function __construct(
        public readonly array $global = [],
        public readonly array $categories = [],
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromWire(array $body): self
    {
        $categories = [];
        foreach (Wire::map($body['categories'] ?? null) as $key => $channels) {
            $categories[(string) $key] = Wire::flags($channels);
        }

        return new self(Wire::flags($body['global'] ?? null), $categories);
    }
}

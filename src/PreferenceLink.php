<?php

declare(strict_types=1);

namespace Hermesi;

/** A hosted preference page for one subscriber. It needs no login and works for about a year. */
final class PreferenceLink
{
    public function __construct(public readonly string $url)
    {
    }
}

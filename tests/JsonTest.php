<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Hermesi;
use Hermesi\Tests\Support\Plain;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    private function simulate(): Hermesi
    {
        return new Hermesi(simulate: true);
    }

    public function testNamesTheKindOfEnumItRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enum without a value');

        $this->simulate()->events->trigger('a.b', 'u', ['kind' => Plain::One]);
    }

    public function testRefusesAnObjectThatContainsItselfInsteadOfRecursingForever(): void
    {
        $loop = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['again' => $this];
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nested more than');

        $this->simulate()->events->trigger('a.b', 'u', ['loop' => $loop]);
    }

    public function testRefusesAPayloadNestedAbsurdlyDeep(): void
    {
        $deep = ['leaf' => 1];
        for ($i = 0; $i < 70; ++$i) {
            $deep = ['n' => $deep];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nested more than');

        $this->simulate()->events->trigger('a.b', 'u', $deep);
    }

    public function testAcceptsANormalAmountOfNesting(): void
    {
        $deep = ['leaf' => 1];
        for ($i = 0; $i < 20; ++$i) {
            $deep = ['n' => $deep];
        }

        $this->simulate()->events->trigger('a.b', 'u', $deep);

        $this->addToAssertionCount(1);
    }
}

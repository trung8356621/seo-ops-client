<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\IndustryContext\IndustryContextFingerprint;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IndustryContextFingerprintTest extends TestCase
{
    #[Test]
    public function associative_key_order_does_not_change_the_hash(): void
    {
        $fingerprint = new IndustryContextFingerprint;

        self::assertSame(
            $fingerprint->hash(['b' => ['y' => 2, 'x' => 1], 'a' => true]),
            $fingerprint->hash(['a' => true, 'b' => ['x' => 1, 'y' => 2]]),
        );
    }

    #[Test]
    public function values_and_list_order_change_the_hash(): void
    {
        $fingerprint = new IndustryContextFingerprint;

        self::assertNotSame($fingerprint->hash(['value' => 1]), $fingerprint->hash(['value' => '1']));
        self::assertNotSame($fingerprint->hash(['items' => ['a', 'b']]), $fingerprint->hash(['items' => ['b', 'a']]));
    }
}

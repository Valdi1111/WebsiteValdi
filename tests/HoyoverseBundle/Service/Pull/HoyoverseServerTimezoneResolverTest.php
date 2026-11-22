<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HoyoverseServerTimezoneResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function regionProvider(): iterable
    {
        yield 'Europe' => ['prod_gf_eu', '2026-12-02T02:59:59+00:00'];
        yield 'America' => ['prod_gf_us', '2026-12-02T08:59:59+00:00'];
        yield 'Asia' => ['prod_gf_jp', '2026-12-01T19:59:59+00:00'];
        yield 'TW/HK/MO' => ['prod_gf_sg', '2026-12-01T19:59:59+00:00'];
    }

    #[DataProvider('regionProvider')]
    public function testServerWallClockToInstantUsesTheFixedServerOffset(string $region, string $expectedUtc): void
    {
        $resolver = new HoyoverseServerTimezoneResolver();

        // Digits of 2026-12-02 03:59:59 server time, parsed in an unrelated timezone
        $wallClock = new \DateTimeImmutable('2026-12-02 03:59:59', new \DateTimeZone('Pacific/Auckland'));
        $instant = $resolver->serverWallClockToInstant($wallClock, $region);

        $this->assertSame($expectedUtc, $instant->setTimezone(new \DateTimeZone('UTC'))->format('c'));
        $this->assertSame('2026-12-02 03:59:59', $instant->format('Y-m-d H:i:s'));
        $this->assertSame($resolver->getServerTimezoneForRegion($region)->getName(), $instant->getTimezone()->getName());
    }
}

<?php

namespace App\Tests\GamesBundle\Service;

use App\GamesBundle\Service\GameUtilsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GameUtilsServiceTest extends TestCase
{
    private function utils(): GameUtilsService
    {
        return new class extends GameUtilsService {
        };
    }

    /**
     * @return iterable<string, array{0: float, 1: ?int, 2: string}>
     */
    public static function formatTimeProvider(): iterable
    {
        // Every unit by default, as in the stamina and realm currency notifications
        yield 'days to seconds' => [2 * 86400 + 3 * 3600 + 15 * 60, null, '2 days, 3 hr, 15 min, 0 sec'];
        yield 'one day is singular' => [86400 + 3600, null, '1 day, 1 hr, 0 sec'];
        yield 'minutes only' => [125, null, '2 min, 5 sec'];
        yield 'zero' => [0, null, '0 sec'];
        // Two units, as in "Ends in"
        yield 'two units' => [9 * 86400 + 15 * 3600 + 26 * 60 + 12, 2, '9 days, 15 hr'];
        yield 'two units, one day' => [86400 + 12 * 3600 + 59 * 60, 2, '1 day, 12 hr'];
        yield 'two units below a day' => [12 * 3600 + 59 * 60 + 59, 2, '12 hr, 59 min'];
        yield 'at least one unit' => [3 * 86400 + 5, 0, '3 days'];
    }

    #[DataProvider('formatTimeProvider')]
    public function testFormatTime(float $seconds, ?int $maxUnits, string $expected): void
    {
        $this->assertSame($expected, $this->utils()->formatTime($seconds, $maxUnits));
    }
}

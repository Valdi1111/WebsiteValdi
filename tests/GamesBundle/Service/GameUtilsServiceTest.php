<?php

namespace App\Tests\GamesBundle\Service;

use App\GamesBundle\Service\GameUtilsService;
use PHPUnit\Framework\TestCase;

class GameUtilsServiceTest extends TestCase
{
    private function utils(): GameUtilsService
    {
        return new class extends GameUtilsService {
        };
    }

    /**
     * The cases live in DurationFormatterTest.
     */
    public function testFormatTimeDelegatesToTheDurationFormatter(): void
    {
        $this->assertSame('2 days, 3 hr, 15 min, 0 sec', $this->utils()->formatTime(2 * 86400 + 3 * 3600 + 15 * 60));
        $this->assertSame('9 days, 15 hr', $this->utils()->formatTime(9 * 86400 + 15 * 3600 + 26 * 60 + 12, 2));
    }
}

<?php

namespace App\PerfectworldBundle\Model\Game;

use Psr\Log\LoggerInterface;

/**
 * Base contract of a Perfect World game service (located by the perfectworld.game.id / .game.biz tags).
 */
interface GameInterface
{
    public static function getGameBiz(): string;

    public static function getGameId(): int;

    public function getGameName(): string;

    public function getIcon(): ?string;

    public function getLogger(): LoggerInterface;

    /**
     * Region code (e.g. "EU") for an SDK server id, or null when unknown.
     */
    public function getRegionForServer(?string $serverId): ?string;

    /**
     * Timezone used to display server times for a region code.
     */
    public function getTimezoneForRegion(?string $region): string;
}

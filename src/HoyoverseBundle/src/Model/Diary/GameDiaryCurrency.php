<?php

declare(strict_types=1);

namespace App\HoyoverseBundle\Model\Diary;

use App\CoreBundle\Model\LabeledInterface;

/**
 * Represents trackable in-game currencies and resources provided by the HoYoverse Diary APIs.
 *
 * Each supported game exposes two diary ledger streams (type 1 and type 2)
 * recording earned primary premium currencies and secondary resources or gacha passes.
 */
enum GameDiaryCurrency: string implements LabeledInterface
{
    // Genshin Impact
    case PRIMOGEM = 'primogem';
    case MORA = 'mora';

    // Honkai: Star Rail
    case STELLAR_JADE = 'stellar_jade';
    case STAR_RAIL_PASS = 'star_rail_pass';

    // Zenless Zone Zero
    case POLYCHROME = 'polychrome';
    case MASTER_TAPE = 'master_tape';

    /**
     * Returns the human-readable label suitable for UI display and notifications.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PRIMOGEM => 'Primogems',
            self::MORA => 'Mora',
            self::STELLAR_JADE => 'Stellar Jades',
            self::STAR_RAIL_PASS => 'Star Rail Passes',
            self::POLYCHROME => 'Polychromes',
            self::MASTER_TAPE => 'Master Tapes',
        };
    }

    /**
     * Returns the numeric query parameter (`type`) expected by the upstream HoYoverse endpoint.
     *
     * - `1`: Primary premium currency (Primogems, Stellar Jades, Polychromes)
     * - `2`: Secondary resource or aggregated gacha vouchers (Mora, Star Rail Passes, Master Tapes)
     */
    public function getApiType(): int
    {
        return match ($this) {
            self::PRIMOGEM, self::STELLAR_JADE, self::POLYCHROME => 1,
            self::MORA, self::STAR_RAIL_PASS, self::MASTER_TAPE => 2,
        };
    }
}
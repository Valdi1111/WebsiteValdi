<?php

namespace App\GamesBundle\Service\Irminsul;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * How IrminsulPhaseNormalizer reads the banner times of a game. irminsul.gg writes "Y-m-d H:i:s", followed by
 * " UTC+8" when the time is zoned; what a time without a zone means, and which irminsul times are known to be off,
 * depends on the game:
 *
 *  - HoYoverse: a time without zone is the local time of the player's server (offset null). The second half of a
 *    version starts at a fixed server time ($phaseTwoStart), but irminsul sometimes writes another one: the start of
 *    a non-first phase without zone is moved to that time, and the end of a first phase without zone, on the start
 *    date of a non-first phase of the same version, to one second before it.
 *  - Endfield: a time without zone is UTC-5, and every banner ends at 12:00 UTC-5, so an end without zone becomes
 *    11:59:59 (irminsul sometimes writes 16:59:59).
 *  - NTE: every time carries UTC+8, nothing is rewritten.
 *
 * A "first phase" is a version whose last dot-separated component is 1 ("6.1.1", "Luna II.1"; not "1.6.11").
 */
#[Exclude]
final readonly class IrminsulTimeRules
{
    /**
     * @param ?int    $zonelessOffset  Hours from UTC of a time without zone, null for the local time of the player's server
     * @param ?string $phaseTwoStart   Server time (H:i:s) at which a non-first phase starts, null when not enforced
     * @param ?string $zonelessEndTime Time of day (H:i:s) forced on every end without zone, null when not enforced
     */
    private function __construct(
        public ?int $zonelessOffset,
        public ?string $phaseTwoStart,
        public ?string $zonelessEndTime,
    ) {
    }

    /**
     * Genshin Impact ("18:00:00"), Honkai: Star Rail and Zenless Zone Zero ("12:00:00").
     */
    public static function hoyoverse(string $phaseTwoStart): self
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $phaseTwoStart)) {
            throw new \InvalidArgumentException(sprintf('The phase two start must be a "H:i:s" time, "%s" given.', $phaseTwoStart));
        }

        return new self(null, $phaseTwoStart, null);
    }

    /**
     * Arknights: Endfield.
     */
    public static function endfield(): self
    {
        return new self(-5, null, '11:59:59');
    }

    /**
     * Neverness to Everness.
     */
    public static function nte(): self
    {
        return new self(null, null, null);
    }
}

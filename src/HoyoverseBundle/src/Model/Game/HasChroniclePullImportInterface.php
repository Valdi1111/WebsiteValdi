<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Model\RuntimeAccountData;

/**
 * The game lists the single pulls in the HoYoLAB battle chronicle, readable with the HoYoLAB web cookies
 * (ltoken_v2, ltuid_v2, ltmid_v2) and no authkey: the automatic sync then works without stoken_v2.
 * Record ids are the same pull_id values returned by getGachaLog.
 */
interface HasChroniclePullImportInterface extends HasPullTrackerInterface
{
    public function getUrlChroniclePulls(): string;

    /**
     * @return array<string, string> banner key of getGachaConfig() => chronicle gacha_type (e.g. "2" => "GACHA_TYPE_CHARACTER_UP")
     */
    public function getChronicleGachaTypes(): array;

    /**
     * @return array<string, string> chronicle item_type => item type returned by getGachaLog (e.g. "ITEM_TYPE_AVATAR" => "Agents")
     */
    public function getChronicleItemTypes(): array;

    /**
     * Fetches a single page of pulls (newest first) for the given banner key and optional end_id cursor
     * (pull_id of the last record of the previous page).
     *
     * @return array<HoyoversePull>
     */
    public function fetchChroniclePullsPage(RuntimeAccountData $account, string $gachaType, string $endId = '0'): array;
}

<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Game\AwardData;
use App\GamesBundle\Model\Game\CheckInResult;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Model\SignInfo;

interface HasHoyolabCheckInInterface
{
    public function getActId(): string;

    public function getSignGame(): string;

    public function getUrlInfo(): string;

    public function getUrlHome(): string;

    public function getUrlSign(): string;

    public function checkIn(RuntimeAccountData $account): CheckInResult;

    /**
     * Performs the daily check-in operation (sign).
     */
    public function signIn(RuntimeAccountData $account): void;

    /**
     * Fetches current check-in status information.
     */
    public function getSignInfo(RuntimeAccountData $account): SignInfo;

    /**
     * Fetches the list of available monthly rewards.
     * @return AwardData[]
     */
    public function getAwardsData(RuntimeAccountData $account): array;

    public function getCheckInSuccessMessage(): string;

    public function getCheckInSignedMessage(): string;
}
<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Game\AwardData;
use App\GamesBundle\Model\Game\CheckInResult;
use App\HoyoverseBundle\Exception\RetrieveSignInfoException;
use App\HoyoverseBundle\Exception\SignInFailedException;
use App\HoyoverseBundle\Exception\SignInRiskException;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Model\SignInfo;

interface HasHoyolabCheckInInterface
{
    public function getActId(): string;

    public function getSignGame(): string;

    public function getUrlInfo(): string;

    public function getUrlHome(): string;

    public function getUrlSign(): string;

    /**
     * @throws SignInRiskException when HoYoLAB asks for a captcha: the reward was not claimed
     */
    public function checkIn(RuntimeAccountData $account): CheckInResult;

    /**
     * Performs the daily check-in operation (sign).
     *
     * @throws SignInRiskException when HoYoLAB asks for a captcha: the answer looks fine, but nothing was claimed
     * @throws SignInFailedException
     */
    public function signIn(RuntimeAccountData $account): void;

    /**
     * Fetches current check-in status information.
     *
     * @throws RetrieveSignInfoException when the request fails or the answer has no readable sign state
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
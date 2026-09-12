<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\AwardData;
use App\HoyoverseBundle\Model\CheckInResult;
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
     * Esegue l'operazione di daily check-in (sign)
     */
    public function signIn(RuntimeAccountData $account): void;

    /**
     * Recupera le informazioni sullo stato del check-in
     */
    public function getSignInfo(RuntimeAccountData $account): SignInfo;

    /**
     * Recupera la lista dei premi disponibili
     * @return AwardData[]
     */
    public function getAwardsData(RuntimeAccountData $account): array;
}
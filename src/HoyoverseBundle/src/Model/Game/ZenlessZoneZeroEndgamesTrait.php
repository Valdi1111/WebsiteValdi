<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\RetrieveDeadlyAssaultDataException;
use App\HoyoverseBundle\Exception\RetrieveShiyuDefenseDataException;
use App\HoyoverseBundle\Model\Endgame\ShiyuDefenseData;
use App\HoyoverseBundle\Model\Endgame\DeadlyAssaultData;
use App\CoreBundle\Model\Game\HasEndgamesInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasEndgamesInterface
 */
trait ZenlessZoneZeroEndgamesTrait
{

    public function getUrlShiyuDefense(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record_zzz/api/zzz/hadal_mem_abstract_info";
    }

    public function getShiyuDefense(RuntimeAccountData $account)
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlShiyuDefense(),
            auth: $account,
            query: [
                'uid' => $account->getGameProfile()->getGameUid(),
                'region' => $account->getGameProfile()->getRegion(),
                'schedule_type' => 1,
            ],
            exceptionClass: RetrieveShiyuDefenseDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            ShiyuDefenseData::class
        );
    }


    public function getUrlDeadlyAssault(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record_zzz/api/zzz/hadal_info_v2";
    }

    public function getDeadlyAssault(RuntimeAccountData $account)
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlDeadlyAssault(),
            auth: $account,
            query: [
                'role_id' => $account->getGameProfile()->getGameUid(),
                'server' => $account->getGameProfile()->getRegion(),
                'schedule_type' => 1,
                'without_v2_detail' => "true",
            ],
            exceptionClass: RetrieveDeadlyAssaultDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            DeadlyAssaultData::class
        );
    }

}

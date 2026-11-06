<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\RetrieveSpiralAbyssDataException;
use App\HoyoverseBundle\Exception\RetrieveStygianOnslaughtDataException;
use App\HoyoverseBundle\Exception\RetrieveTheaterLobbyDataException;
use App\HoyoverseBundle\Model\Endgame\SpiralAbyssData;
use App\HoyoverseBundle\Model\Endgame\StygianOnslaughtData;
use App\HoyoverseBundle\Model\Endgame\TheaterLobbyData;
use App\CoreBundle\Model\Game\HasEndgamesInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasEndgamesInterface
 */
trait GenshinImpactEndgamesTrait
{

    public function getUrlSpiralAbyss(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record/genshin/api/spiralAbyss";
    }

    public function getSpiralAbyss(RuntimeAccountData $account)
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlSpiralAbyss(),
            auth: $account,
            query: [
                'role_id' => $account->getGameProfile()->getGameUid(),
                'server' => $account->getGameProfile()->getRegion(),
                'schedule_type' => 1,
            ],
            exceptionClass: RetrieveSpiralAbyssDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            SpiralAbyssData::class
        );
    }

    public function getUrlTheaterLobby(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record/genshin/api/role_combat";
    }

    public function getTheaterLobby(RuntimeAccountData $account)
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlTheaterLobby(),
            auth: $account,
            query: [
                'role_id' => $account->getGameProfile()->getGameUid(),
                'server' => $account->getGameProfile()->getRegion(),
                'need_detail' => "false",
            ],
            exceptionClass: RetrieveTheaterLobbyDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            TheaterLobbyData::class
        );
    }

    public function getUrlStygianOnslaught(): string
    {
        return "https://sg-act-public-api.hoyolab.com/event/game_record/genshin/api/hard_challenge";
    }

    public function getStygianOnslaught(RuntimeAccountData $account)
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlStygianOnslaught(),
            auth: $account,
            query: [
                'role_id' => $account->getGameProfile()->getGameUid(),
                'server' => $account->getGameProfile()->getRegion(),
            ],
            exceptionClass: RetrieveStygianOnslaughtDataException::class
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            StygianOnslaughtData::class
        );
    }

}

<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use App\HoyoverseBundle\Exception\RetrieveDiaryDataException;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Diary\GameDiaryInfoInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasDiaryInterface
 */
trait DiaryTrait
{

    public function getDiaryInfo(RuntimeAccountData $account, string $month): GameDiaryInfoInterface
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlDiaryInfo(),
            auth: $account,
            query: [
                'uid' => $account->getGameProfile()->getGameUid(),
                'region' => $account->getGameProfile()->getRegion(),
                'month' => $month,
            ],
            exceptionClass: RetrieveDiaryDataException::class,
            extraHeaders: ['DS' => $this->getHoyolabUtils()->generateDS()]
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            $this->getDiaryInfoClass()
        );
    }

    public function getDiaryItems(RuntimeAccountData $account, GameDiaryCurrency $currency, string $month, int $currentPage = 1, int $pageSize = 100): array
    {
        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlDiaryDetail(),
            auth: $account,
            query: [
                'uid' => $account->getGameProfile()->getGameUid(),
                'region' => $account->getGameProfile()->getRegion(),
                'month' => $month,
                'type' => $currency->getApiType(),
                'current_page' => $currentPage,
                'page_size' => $pageSize,
            ],
            exceptionClass: RetrieveDiaryDataException::class,
            extraHeaders: ['DS' => $this->getHoyolabUtils()->generateDS()]
        );

        return $this->getDenormalizer()->denormalize(
            $body['data']['list'] ?? [],
            $this->getDiaryItemClass() . '[]'
        );
    }

    public function getDiaryEntries(RuntimeAccountData $account, GameDiaryCurrency $currency, string $month, string $period, int $currentPage = 1, int $pageSize = 100): array
    {
        $diaryEntries = [];
        $diaryItems = $this->getDiaryItems($account, $currency, $month, $currentPage, $pageSize);
        foreach ($diaryItems as $diaryItem) {
            $diaryEntry = $this->getObjectMapper()->map($diaryItem, HoyoverseDiaryEntry::class)
                ->setCurrency($currency)
                ->setPeriod($period);
            $diaryEntries[] = $diaryEntry;
        }
        return $diaryEntries;
    }

}
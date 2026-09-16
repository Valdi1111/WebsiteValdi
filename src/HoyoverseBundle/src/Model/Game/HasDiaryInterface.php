<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Diary\GameDiaryItemInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasDiaryInterface
{
    public function getUrlDiaryInfo(): string;

    public function getUrlDiaryDetail(): string;

    /**
     * @return class-string<GameDiaryItemInterface>
     */
    public function getDiaryItemClass(): string;

    public function getDiaryPeriod(\DateTimeInterface $date): string;

    public function getDiaryInfo(RuntimeAccountData $account, string $month): array;

    /**
     * @return array<GameDiaryItemInterface>
     */
    public function getDiaryItems(RuntimeAccountData $account, GameDiaryCurrency $currency, string $month, int $currentPage = 1, int $pageSize = 100): array;

    /**
     * @return array<HoyoverseDiaryEntry>
     */
    public function getDiaryEntries(RuntimeAccountData $account, GameDiaryCurrency $currency, string $month, string $period, int $currentPage = 1, int $pageSize = 100): array;
}
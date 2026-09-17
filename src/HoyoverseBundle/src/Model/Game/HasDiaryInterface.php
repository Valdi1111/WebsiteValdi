<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Diary\GameDiaryInfoInterface;
use App\HoyoverseBundle\Model\Diary\GameDiaryItemInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;

/**
 * @template N of GameDiaryInfoInterface
 * @template T of GameDiaryItemInterface
 */
interface HasDiaryInterface
{
    public function getUrlDiaryInfo(): string;

    public function getUrlDiaryDetail(): string;

    /**
     * @return class-string<N>
     */
    public function getDiaryInfoClass(): string;

    /**
     * @return class-string<T>
     */
    public function getDiaryItemClass(): string;

    public function getDiaryPeriod(\DateTimeInterface $date): string;

    /**
     * @return N
     */
    public function getDiaryInfo(RuntimeAccountData $account, string $month): GameDiaryInfoInterface;

    /**
     * @return array<T>
     */
    public function getDiaryItems(RuntimeAccountData $account, GameDiaryCurrency $currency, string $month, int $currentPage = 1, int $pageSize = 100): array;

    /**
     * @return array<HoyoverseDiaryEntry>
     */
    public function getDiaryEntries(RuntimeAccountData $account, GameDiaryCurrency $currency, string $month, string $period, int $currentPage = 1, int $pageSize = 100): array;
}
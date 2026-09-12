<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Model\RuntimeAccountData;

interface HasDiaryInterface
{
    public function getUrlDiaryInfo(): string;

    public function getUrlDiaryDetail(): string;

    public function getDiaryMonth(int $month, int $year): int|string;

    public function getDiaryInfo(RuntimeAccountData $account): array;

    public function getDiaryDetail(RuntimeAccountData $account): array;
}
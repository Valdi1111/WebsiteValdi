<?php

namespace App\HoyoverseBundle\Message\Profile;

use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Represents a pagination task to fetch, parse, and persist a single page
 * of diary ledger entries for a specific game profile, currency, and billing period.
 *
 * Dispatched sequentially with a rate-limiting delay to iteratively traverse
 * upstream HoYoverse diary endpoints without triggering anti-scraping blocks.
 */
#[AsMessage('hoyoverse_diary')]
class SyncDiaryPageProfileMessage implements ProfileTaskMessageInterface
{
    public function __construct(
        private readonly int               $gameProfileId,
        private readonly GameDiaryCurrency $currency,
        private readonly string            $month,
        private readonly string            $period,
        private readonly int               $page
    )
    {
    }

    public function getGameProfileId(): int
    {
        return $this->gameProfileId;
    }

    public function getCurrency(): GameDiaryCurrency
    {
        return $this->currency;
    }

    public function getMonth(): string
    {
        return $this->month;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function getPage(): int
    {
        return $this->page;
    }
}
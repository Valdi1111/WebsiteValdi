<?php

namespace App\HoyoverseBundle\Message\Profile;

use App\CoreBundle\Message\ProfileTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Represents a pagination task to fetch, parse, and persist a single page
 * of gacha/pull records for a specific game profile and banner type.
 *
 * Dispatched sequentially with a jitter rate-limiting delay on the dedicated
 * 'hoyoverse_pulls' channel to iteratively traverse upstream HoYoverse gacha
 * endpoints without triggering anti-scraping blocks.
 */
#[AsMessage('hoyoverse_pulls')]
class SyncPullsPageProfileMessage implements ProfileTaskMessageInterface
{
    /**
     * @param array<string, mixed> $extraQueryParams
     */
    public function __construct(
        private readonly int     $gameProfileId,
        private readonly string  $gachaType,
        private readonly string  $endId = '0',
        private readonly ?string $authkey = null,
        private readonly array   $extraQueryParams = []
    ) {
    }

    public function getGameProfileId(): int
    {
        return $this->gameProfileId;
    }

    public function getGachaType(): string
    {
        return $this->gachaType;
    }

    public function getEndId(): string
    {
        return $this->endId;
    }

    public function getAuthkey(): ?string
    {
        return $this->authkey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraQueryParams(): array
    {
        return $this->extraQueryParams;
    }
}

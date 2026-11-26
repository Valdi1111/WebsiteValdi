<?php

namespace App\GryphlineBundle\Message\Profile;

use App\GamesBundle\Message\ProfileTaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Represents a pagination task to fetch, parse, and persist a single page
 * of gacha/pull records for a specific Gryphline game profile and pool category.
 *
 * Dispatched sequentially with a jitter rate-limiting delay on the dedicated
 * 'gryphline_pulls' channel to iteratively traverse upstream Gryphline gacha
 * endpoints without triggering anti-scraping blocks.
 */
#[AsMessage('gryphline_pulls')]
class SyncPullsPageProfileMessage implements ProfileTaskMessageInterface
{
    /*
     * Pool being read and pools still to read, for the streams read banner by banner (Endfield Special
     * Headhunting). Plain properties with defaults: messages queued before they existed unserialize with none.
     */
    private ?string $poolId = null;

    /** @var list<string> */
    private array $pendingPoolIds = [];

    /**
     * @param list<string> $pendingPoolIds
     */
    public function __construct(
        private readonly int     $gameProfileId,
        private readonly string  $category,
        private readonly ?string $seqId = null,
        private readonly ?string $u8Token = null,
        private readonly ?string $operationId = null,
        ?string $poolId = null,
        array $pendingPoolIds = [],
    ) {
        $this->poolId = $poolId;
        $this->pendingPoolIds = array_values($pendingPoolIds);
    }

    public function getGameProfileId(): int
    {
        return $this->gameProfileId;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getSeqId(): ?string
    {
        return $this->seqId;
    }

    public function getU8Token(): ?string
    {
        return $this->u8Token;
    }

    public function getOperationId(): ?string
    {
        return $this->operationId;
    }

    public function getPoolId(): ?string
    {
        return $this->poolId;
    }

    /**
     * @return list<string>
     */
    public function getPendingPoolIds(): array
    {
        return $this->pendingPoolIds;
    }
}

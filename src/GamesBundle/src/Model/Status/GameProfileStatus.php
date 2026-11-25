<?php

namespace App\GamesBundle\Model\Status;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileRef;

/**
 * What GameProfileStatusCollector read for one profile: a result per section, in section order, and the fetch time
 * of the notes they share. $error is set when the profile could not be read at all (no game service, unreadable
 * account); its sections are then empty.
 */
final readonly class GameProfileStatus
{
    /**
     * @param array<string, SectionResult> $sections by section key
     */
    public function __construct(
        public GameProfileInterface $profile,
        public GameProfileRef $ref,
        public ?GameDescriptor $game,
        public AccountHealth $accountHealth,
        public array $sections = [],
        public ?\DateTimeImmutable $fetchedAt = null,
        public ?\Throwable $error = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        if ($this->error !== null) {
            return false;
        }
        foreach ($this->sections as $result) {
            if (!$result->isSuccessful()) {
                return false;
            }
        }

        return true;
    }
}

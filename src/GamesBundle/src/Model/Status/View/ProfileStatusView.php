<?php

namespace App\GamesBundle\Model\Status\View;

use App\GamesBundle\Model\Profile\AccountHealth;

/**
 * Status of one game profile for the site (GET /api/{provider}/gameProfiles/{id}/status), serialized as it is.
 *
 * Both times are server times, so the page computes the age of the data without the clock of the browser. $error is
 * the generic line of a profile that could not be read at all; its sections are then empty.
 */
final readonly class ProfileStatusView
{
    /**
     * @param array{slug: string, name: string}|null $game
     * @param list<ProfileStatusSectionView>         $sections in display order
     */
    public function __construct(
        public string $ref,
        public ?array $game,
        public \DateTimeImmutable $generatedAt,
        public ?\DateTimeImmutable $fetchedAt,
        public AccountHealth $account,
        public ?string $error,
        public array $sections,
    ) {
    }
}

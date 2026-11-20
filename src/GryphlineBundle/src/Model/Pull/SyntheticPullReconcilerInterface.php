<?php

namespace App\GryphlineBundle\Model\Pull;

use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Model\Game\GameInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by backup importers that generate synthetic seq_id values
 * (when the backup source does not store official Gryphline seq_ids).
 *
 * During live Gryphline sync, SyncPullsPageProfileHandler delegates to registered
 * reconcilers so matching synthetic records are promoted in-place instead of
 * creating duplicates.
 */
#[AutoconfigureTag('gryphline.synthetic_pull_reconciler')]
interface SyntheticPullReconcilerInterface
{
    /**
     * @param class-string<GameInterface>|GameInterface $gameService
     */
    public function supportsGameService(string|GameInterface $gameService): bool;

    /**
     * Attempts to match an incoming official Gryphline pull against an existing
     * synthetic pull for the same profile and promote it in-place.
     *
     * Returns the promoted managed entity if matched, or null if no synthetic match exists.
     */
    public function reconcile(
        GryphlineGameProfile $profile,
        GryphlinePull $incomingRealPull,
        GameInterface $gameService,
    ): ?GryphlinePull;
}

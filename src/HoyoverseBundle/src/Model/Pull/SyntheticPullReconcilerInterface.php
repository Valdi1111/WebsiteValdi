<?php

namespace App\HoyoverseBundle\Model\Pull;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Model\Game\GameInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Optional capability implemented by backup importers that generate synthetic pull IDs
 * (when the upstream backup source does not retain HoYoverse's original record IDs).
 *
 * Allows live HoYoverse sync to promote matching synthetic pulls in-place to their real
 * record IDs without introducing duplicates or coupling live sync to specific backup formats.
 */
#[AutoconfigureTag('hoyoverse.synthetic_pull_reconciler')]
interface SyntheticPullReconcilerInterface
{
    public function supportsGameService(string|GameInterface $gameService): bool;

    /**
     * Attempts to find a stored synthetic pull matching the incoming real API pull and promote
     * it in-place (updating its pullId and payload). Returns the promoted entity, or null if none matched.
     */
    public function reconcile(
        HoyoverseGameProfile $profile,
        HoyoversePull $incomingRealPull,
        GameInterface $gameService,
    ): ?HoyoversePull;
}

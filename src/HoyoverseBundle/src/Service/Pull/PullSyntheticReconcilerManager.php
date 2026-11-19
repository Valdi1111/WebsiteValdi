<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Pull\SyntheticPullReconcilerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Delegates reconciliation of synthetic backup pulls (such as Paimon.moe records)
 * to the importer(s) responsible for them during live HoYoverse pull syncs.
 */
class PullSyntheticReconcilerManager
{
    /** @var list<SyntheticPullReconcilerInterface> */
    private array $reconcilers = [];

    /**
     * @param iterable<SyntheticPullReconcilerInterface> $reconcilers
     */
    public function __construct(
        #[AutowireIterator('hoyoverse.synthetic_pull_reconciler')]
        iterable $reconcilers,
    ) {
        foreach ($reconcilers as $reconciler) {
            $this->reconcilers[] = $reconciler;
        }
    }

    public function reconcile(
        HoyoverseGameProfile $profile,
        HoyoversePull $incomingRealPull,
        GameInterface $gameService,
    ): ?HoyoversePull {
        foreach ($this->reconcilers as $reconciler) {
            if (!$reconciler->supportsGameService($gameService)) {
                continue;
            }

            $promoted = $reconciler->reconcile($profile, $incomingRealPull, $gameService);
            if ($promoted !== null) {
                return $promoted;
            }
        }

        return null;
    }
}

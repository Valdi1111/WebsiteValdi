<?php

namespace App\GryphlineBundle\Service\Pull;

use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Pull\SyntheticPullReconcilerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class PullSyntheticReconcilerManager
{
    /** @var list<SyntheticPullReconcilerInterface> */
    private array $reconcilers = [];

    /**
     * @param iterable<SyntheticPullReconcilerInterface> $reconcilers
     */
    public function __construct(
        #[AutowireIterator('gryphline.synthetic_pull_reconciler')]
        iterable $reconcilers,
    ) {
        foreach ($reconcilers as $reconciler) {
            $this->reconcilers[] = $reconciler;
        }
    }

    /**
     * Delegates to registered synthetic pull reconcilers for the given game service.
     * Returns the promoted managed entity if any reconciler matched and updated one.
     */
    public function reconcile(
        GryphlineGameProfile $profile,
        GryphlinePull $incomingRealPull,
        GameInterface $gameService,
    ): ?GryphlinePull {
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

<?php

namespace App\PerfectworldBundle\Service\Catalog;

use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Game\HasPullTrackerInterface;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Pull\ManualPullService;
use App\PerfectworldBundle\Service\Pull\PullBackupImportService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Re-resolves name, rarity and reward type of every stored pull from the current catalog, so items
 * that the exporter did not know yet (blank name, null rarity) get fixed after a catalog update.
 *
 * The catalog never downgrades a row: an id missing from the catalog, an empty catalog name or a null
 * catalog rank keeps the stored value.
 *
 * A new rarity or type can change which rows are S, so every profile with a changed row is then
 * realigned (manual S merges and filler rows), under its profile lock taken without waiting. A
 * profile whose lock is held is skipped: the import or manual edit holding it, or the next one,
 * realigns it anyway.
 */
class NteCatalogBackfiller
{
    private const int BATCH_SIZE = 250;
    private const int NAME_MAX_LENGTH = 128;
    private const float LOCK_TTL_SECONDS = 60.0;

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        private readonly NteCatalog $catalog,
        private readonly PerfectworldPullRepository $pullRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManualPullService $manualPullService,
        private readonly LockFactory $lockFactory,
        #[AutowireLocator(services: 'perfectworld.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
        #[Target('perfectworld')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int number of rows that changed
     */
    public function backfill(): int
    {
        if (!$this->catalog->isLoaded()) {
            return 0;
        }

        $changed = 0;
        $offset = 0;
        $changedProfileIds = [];

        // A few thousand rows at most: offset paging on the id is stable because the backfill does not
        // change which rows exist, and clear() keeps the unit of work small.
        do {
            $pulls = $this->pullRepository->findBy([], ['id' => 'ASC'], self::BATCH_SIZE, $offset);
            $batchChanged = 0;
            foreach ($pulls as $pull) {
                if ($this->apply($pull)) {
                    $batchChanged++;
                    $profileId = $pull->getGameProfile()?->getId();
                    if ($profileId !== null) {
                        $changedProfileIds[$profileId] = true;
                    }
                }
            }

            if ($batchChanged > 0) {
                $this->entityManager->flush();
            }
            $this->entityManager->clear();

            $changed += $batchChanged;
            $offset += self::BATCH_SIZE;
        } while (count($pulls) === self::BATCH_SIZE);

        foreach (array_keys($changedProfileIds) as $profileId) {
            $this->realignProfile($profileId);
        }

        return $changed;
    }

    /**
     * realignAll() for one profile, unless another pull operation holds its lock.
     */
    private function realignProfile(int $profileId): void
    {
        $profile = $this->entityManager->find(PerfectworldGameProfile::class, $profileId);
        if ($profile === null) {
            return;
        }

        $gameId = (int) $profile->getGameId();
        $gameService = $this->locatorByGameId->has($gameId) ? $this->locatorByGameId->get($gameId) : null;
        if (!$gameService instanceof GameInterface || !$gameService instanceof HasPullTrackerInterface || !$gameService instanceof HasManualPullsInterface) {
            return;
        }

        $lock = $this->lockFactory->createLock(PullBackupImportService::getProfileLockKey($profileId), self::LOCK_TTL_SECONDS);
        if (!$lock->acquire(false)) {
            $this->logger->info(sprintf(
                'Pulls of profile #%d not realigned after the catalog backfill: another pull operation holds its lock (it or the next one realigns them).',
                $profileId,
            ));

            return;
        }

        try {
            $this->manualPullService->realignAll($profile, $gameService);
        } finally {
            $lock->release();
            $this->entityManager->clear();
        }
    }

    /**
     * Applies the catalog values to one pull.
     *
     * @return bool whether any field changed
     */
    public function apply(PerfectworldPull $pull): bool
    {
        $entry = $this->catalog->find((string) $pull->getRewardId());
        if ($entry === null) {
            return false;
        }

        $changed = false;

        $name = mb_substr(trim($entry['name']), 0, self::NAME_MAX_LENGTH);
        if ($name !== '' && $name !== $pull->getName()) {
            $pull->setName($name);
            $changed = true;
        }

        if ($entry['rarity'] !== null && $entry['rarity'] !== $pull->getRarity()) {
            $pull->setRarity($entry['rarity']);
            $changed = true;
        }

        if ($entry['type'] !== '' && $entry['type'] !== $pull->getRewardType()) {
            $pull->setRewardType($entry['type']);
            $changed = true;
        }

        return $changed;
    }
}

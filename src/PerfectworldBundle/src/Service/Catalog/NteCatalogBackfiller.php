<?php

namespace App\PerfectworldBundle\Service\Catalog;

use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Re-resolves name, rarity and reward type of every stored pull from the current catalog, so items
 * that the exporter did not know yet (blank name, null rarity) get fixed after a catalog update.
 *
 * The catalog never downgrades a row: an id missing from the catalog, an empty catalog name or a null
 * catalog rank keeps the stored value.
 */
class NteCatalogBackfiller
{
    private const int BATCH_SIZE = 250;
    private const int NAME_MAX_LENGTH = 128;

    public function __construct(
        private readonly NteCatalog $catalog,
        private readonly PerfectworldPullRepository $pullRepository,
        private readonly EntityManagerInterface $entityManager,
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

        // A few thousand rows at most: offset paging on the id is stable because the backfill does not
        // change which rows exist, and clear() keeps the unit of work small.
        do {
            $pulls = $this->pullRepository->findBy([], ['id' => 'ASC'], self::BATCH_SIZE, $offset);
            $batchChanged = 0;
            foreach ($pulls as $pull) {
                $batchChanged += $this->apply($pull) ? 1 : 0;
            }

            if ($batchChanged > 0) {
                $this->entityManager->flush();
            }
            $this->entityManager->clear();

            $changed += $batchChanged;
            $offset += self::BATCH_SIZE;
        } while (count($pulls) === self::BATCH_SIZE);

        return $changed;
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

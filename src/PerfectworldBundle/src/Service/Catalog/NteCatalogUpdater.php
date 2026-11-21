<?php

namespace App\PerfectworldBundle\Service\Catalog;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Rebuilds the NTE catalog and re-resolves the stored pulls (used by the console command and the
 * scheduled catalog_update task).
 */
class NteCatalogUpdater
{
    public function __construct(
        private readonly NteCatalogBuilder $builder,
        private readonly NteCatalog $catalog,
        private readonly NteCatalogBackfiller $backfiller,
        #[Target('perfectworld')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{catalog: array<string, mixed>, new_items: int, backfilled: int}
     *
     * @throws \RuntimeException when the build is aborted (the previous catalog is kept)
     */
    public function update(?string $ref = null): array
    {
        $previousItems = $this->catalog->all()['items'];

        try {
            $catalog = $this->builder->build($ref);
        } catch (\Throwable $e) {
            $this->logger->error('NTE catalog update aborted, previous catalog kept', [
                'ref' => $ref ?? NteCatalogBuilder::DEFAULT_REF,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(sprintf('Catalog update aborted, previous catalog kept: %s', $e->getMessage()), 0, $e);
        }

        $this->catalog->save($catalog);
        $backfilled = $this->backfiller->backfill();
        $newItems = count(array_diff_key($catalog['items'], $previousItems));

        $this->logger->info(sprintf(
            'NTE catalog updated to %s (%s): %d items (%d new), %d pulls backfilled.',
            $catalog['sha'],
            $catalog['version'],
            count($catalog['items']),
            $newItems,
            $backfilled,
        ));

        return ['catalog' => $catalog, 'new_items' => $newItems, 'backfilled' => $backfilled];
    }
}

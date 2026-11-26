<?php

namespace App\HoyoverseBundle\Service\Catalog;

use Psr\Log\LoggerInterface;

/**
 * Rebuilds and saves the banner catalog of a HoYoverse game (used by the hoyoverse:<game>-catalog:update command and
 * the scheduled <game>_catalog_update task). No backfill is needed: rate-up flags are computed when pulls are read.
 */
abstract class AbstractIrminsulCatalogUpdater
{
    public function __construct(
        private readonly AbstractIrminsulCatalogBuilder $builder,
        private readonly AbstractIrminsulCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getGameName(): string
    {
        return $this->builder->getGameName();
    }

    /**
     * @return array{fetched_at: string, items: int, phases: int}
     *
     * @throws \RuntimeException when the build is aborted (the previous catalog is kept)
     */
    public function update(): array
    {
        try {
            $catalog = $this->builder->build();
            // A truncated but valid file would otherwise replace the whole history until the next update
            $stored = count($this->catalog->all()['phases']);
            $built = count($catalog['phases'] ?? []);
            if ($built < $stored) {
                throw new \RuntimeException(sprintf('irminsul.gg returned %d phases, fewer than the %d of the stored catalog', $built, $stored));
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('%s catalog update aborted, previous catalog kept', $this->getGameName()), [
                'source' => 'irminsul.gg/' . $this->catalog::getGameSlug(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(sprintf('%s catalog update aborted, previous catalog kept: %s', $this->getGameName(), $e->getMessage()), 0, $e);
        }

        $this->catalog->save($catalog);
        // Counted on the stored catalog, which keeps only well-formed entries
        $saved = $this->catalog->all();

        $this->logger->info(sprintf(
            '%s catalog updated from irminsul.gg: %d items, %d phases.',
            $this->getGameName(),
            count($saved['items']),
            count($saved['phases']),
        ));

        return [
            'fetched_at' => (string) $saved['fetched_at'],
            'items' => count($saved['items']),
            'phases' => count($saved['phases']),
        ];
    }
}

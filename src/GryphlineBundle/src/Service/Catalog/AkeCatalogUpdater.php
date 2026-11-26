<?php

namespace App\GryphlineBundle\Service\Catalog;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Rebuilds and saves the Endfield catalog (used by the console command and the scheduled
 * catalog_update task). No backfill is needed: stored pulls already carry the names from the API.
 */
class AkeCatalogUpdater
{
    public function __construct(
        private readonly AkeCatalogBuilder $builder,
        private readonly AkeCatalog $catalog,
        #[Target('gryphline')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{catalog: array{version: string, built_at: string, items: array<string, array{id: string, name: string, rarity: ?int, type: string}>, pools: array<string, array{id: string, category: string, type: int, up: list<string>}>}, new_items: int}
     *
     * @throws \RuntimeException when the build is aborted (the previous catalog is kept)
     */
    public function update(?string $versionId = null): array
    {
        $previousItems = $this->catalog->all()['items'];

        try {
            $catalog = $this->builder->build($versionId);
            if ($catalog['items'] === []) {
                throw new \RuntimeException('The built catalog is empty.');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Endfield catalog update aborted, previous catalog kept', [
                'version' => $versionId ?? 'latest',
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(sprintf('Catalog update aborted, previous catalog kept: %s', $e->getMessage()), 0, $e);
        }

        $this->catalog->save($catalog);
        $newItems = count(array_diff_key($catalog['items'], $previousItems));

        $this->logger->info(sprintf(
            'Endfield catalog updated to %s: %d items (%d new).',
            $catalog['version'],
            count($catalog['items']),
            $newItems,
        ));

        return ['catalog' => $catalog, 'new_items' => $newItems];
    }
}

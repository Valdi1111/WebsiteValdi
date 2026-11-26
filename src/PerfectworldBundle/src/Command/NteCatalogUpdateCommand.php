<?php

namespace App\PerfectworldBundle\Command;

use App\PerfectworldBundle\Service\Catalog\NteCatalogUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual catalog update; the weekly update runs as the "catalog_update" task of the perfectworld schedule.
 */
#[AsCommand(name: 'perfectworld:nte-catalog:update', description: 'Rebuild the Neverness to Everness item catalog from NTE_Assets and backfill stored pulls')]
class NteCatalogUpdateCommand extends Command
{
    public function __construct(
        private readonly NteCatalogUpdater $updater,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('NTE_Assets git ref to build from (branch, tag or commit SHA), default "main"')]
        ?string $ref = null,
    ): int {
        try {
            ['catalog' => $catalog, 'new_items' => $newItems, 'backfilled' => $backfilled] = $this->updater->update($ref);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $types = array_count_values(array_column($catalog['items'], 'type'));
        ksort($types);

        $rows = [
            ['Commit' => $catalog['sha']],
            ['Version' => $catalog['version']],
            ['Built at' => $catalog['built_at']],
            ['Items' => sprintf('%d (%d new)', count($catalog['items']), $newItems)],
        ];
        foreach ($types as $type => $count) {
            $rows[] = ['  ' . $type => (string) $count];
        }
        $rows[] = ['Avatars' => (string) count($catalog['avatars'])];
        $rows[] = ['Pools' => (string) count($catalog['pools'])];
        $rows[] = ['Banners' => (string) count($catalog['banners'])];
        $rows[] = ['Backfilled pulls' => (string) $backfilled];

        $io->title('NTE catalog updated');
        $io->definitionList(...$rows);
        $io->success('Catalog saved.');

        return Command::SUCCESS;
    }
}

<?php

namespace App\GryphlineBundle\Command;

use App\GryphlineBundle\Service\Catalog\EndfieldCatalog;
use App\GryphlineBundle\Service\Catalog\EndfieldCatalogUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual catalog update; the weekly update runs as the "catalog_update" task of the gryphline schedule.
 */
#[AsCommand(name: 'gryphline:endfield-catalog:update', description: 'Rebuild the Endfield operator and weapon catalog from AkeData')]
class EndfieldCatalogUpdateCommand extends Command
{
    public function __construct(
        private readonly EndfieldCatalogUpdater $updater,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        // "--version" is a global console option, hence the longer name
        #[Option('AkeData manifest version id to build from (e.g. "1.6.4@11944529-13"), default the latest one')]
        ?string $manifestVersion = null,
    ): int {
        try {
            ['catalog' => $catalog, 'new_items' => $newItems] = $this->updater->update($manifestVersion);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $counts = [EndfieldCatalog::TYPE_CHAR => 0, EndfieldCatalog::TYPE_WEAPON => 0];
        $sixStars = [EndfieldCatalog::TYPE_CHAR => 0, EndfieldCatalog::TYPE_WEAPON => 0];
        foreach ($catalog['items'] as $item) {
            ++$counts[$item['type']];
            if ($item['rarity'] === 6) {
                ++$sixStars[$item['type']];
            }
        }

        $io->title('Endfield catalog updated');
        $io->definitionList(
            ['Version' => $catalog['version']],
            ['Built at' => $catalog['built_at']],
            ['Items' => sprintf('%d (%d new)', count($catalog['items']), $newItems)],
            ['Operators' => (string) $counts[EndfieldCatalog::TYPE_CHAR]],
            ['Weapons' => (string) $counts[EndfieldCatalog::TYPE_WEAPON]],
            ['6★' => sprintf(
                '%d (%d operators, %d weapons)',
                array_sum($sixStars),
                $sixStars[EndfieldCatalog::TYPE_CHAR],
                $sixStars[EndfieldCatalog::TYPE_WEAPON],
            )],
        );
        $io->success('Catalog saved.');

        return Command::SUCCESS;
    }
}

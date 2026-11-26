<?php

namespace App\GryphlineBundle\Command;

use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use App\GryphlineBundle\Service\Catalog\AkeCatalogUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual catalog update; the weekly update runs as the "catalog_update" task of the gryphline schedule.
 */
#[AsCommand(name: 'gryphline:ake-catalog:update', description: 'Rebuild the Endfield operator, weapon and gacha pool catalog from AkeData and irminsul.gg')]
class AkeCatalogUpdateCommand extends Command
{
    public function __construct(
        private readonly AkeCatalogUpdater $updater,
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

        $counts = [AkeCatalog::TYPE_CHAR => 0, AkeCatalog::TYPE_WEAPON => 0];
        $sixStars = [AkeCatalog::TYPE_CHAR => 0, AkeCatalog::TYPE_WEAPON => 0];
        foreach ($catalog['items'] as $item) {
            ++$counts[$item['type']];
            if ($item['rarity'] === 6) {
                ++$sixStars[$item['type']];
            }
        }

        $pools = [AkeCatalog::TYPE_CHAR => 0, AkeCatalog::TYPE_WEAPON => 0];
        $runs = 0;
        foreach ($catalog['pools'] as $pool) {
            ++$pools[$pool['category']];
            $runs += count($pool['runs']);
        }

        $io->title('Endfield catalog updated');
        $io->definitionList(
            ['Version' => $catalog['version']],
            ['Built at' => $catalog['built_at']],
            ['Items' => sprintf('%d (%d new)', count($catalog['items']), $newItems)],
            ['Operators' => (string) $counts[AkeCatalog::TYPE_CHAR]],
            ['Weapons' => (string) $counts[AkeCatalog::TYPE_WEAPON]],
            ['6★' => sprintf(
                '%d (%d operators, %d weapons)',
                array_sum($sixStars),
                $sixStars[AkeCatalog::TYPE_CHAR],
                $sixStars[AkeCatalog::TYPE_WEAPON],
            )],
            ['Operator pools' => (string) $pools[AkeCatalog::TYPE_CHAR]],
            ['Weapon pools' => (string) $pools[AkeCatalog::TYPE_WEAPON]],
            // irminsul.gg banners mapped to a pool (kept from the previous catalog when irminsul.gg is down)
            ['Banner runs' => (string) $runs],
        );
        // A pool table that could not be read does not abort the build (see the gryphline log)
        foreach (['operator' => AkeCatalog::TYPE_CHAR, 'weapon' => AkeCatalog::TYPE_WEAPON] as $label => $category) {
            if ($pools[$category] === 0) {
                $io->warning(sprintf('No %s pool was stored: the pool table could not be read (see the gryphline log).', $label));
            }
        }
        if ($runs === 0 && $catalog['pools'] !== []) {
            $io->warning('No banner run was stored: irminsul.gg could not be read or matched no pool (see the gryphline log).');
        }
        $io->success('Catalog saved.');

        return Command::SUCCESS;
    }
}

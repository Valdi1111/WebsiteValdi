<?php

namespace App\HoyoverseBundle\Command;

use App\HoyoverseBundle\Service\Catalog\AbstractIrminsulCatalog;
use App\HoyoverseBundle\Service\Catalog\AbstractIrminsulCatalogUpdater;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manual update of a HoYoverse banner catalog from irminsul.gg; the weekly update runs as the "<game>_catalog_update"
 * task of the hoyoverse schedule.
 */
abstract class AbstractIrminsulCatalogUpdateCommand extends Command
{
    public function __construct(
        private readonly AbstractIrminsulCatalogUpdater $updater,
        private readonly AbstractIrminsulCatalog $catalog,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $result = $this->updater->update();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title(sprintf('%s catalog updated', $this->updater->getGameName()));
        $io->definitionList(
            ['Fetched at' => $result['fetched_at']],
            ['Items' => (string) $result['items']],
            ['Character phases' => (string) count($this->catalog->getPhases(AbstractIrminsulCatalog::CATEGORY_CHARACTER))],
            ['Weapon phases' => (string) count($this->catalog->getPhases(AbstractIrminsulCatalog::CATEGORY_WEAPON))],
        );
        $io->success('Catalog saved.');

        return Command::SUCCESS;
    }
}

<?php

namespace App\HoyoverseBundle\Command;

use App\HoyoverseBundle\Service\Catalog\GiCatalog;
use App\HoyoverseBundle\Service\Catalog\GiCatalogUpdater;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Manual catalog update; the weekly update runs as the "gi_catalog_update" task of the hoyoverse schedule.
 */
#[AsCommand(name: 'hoyoverse:gi-catalog:update', description: 'Rebuild the Genshin Impact banner catalog (phases and rate-ups) from irminsul.gg')]
class GiCatalogUpdateCommand extends AbstractIrminsulCatalogUpdateCommand
{
    public function __construct(GiCatalogUpdater $updater, GiCatalog $catalog)
    {
        parent::__construct($updater, $catalog);
    }
}

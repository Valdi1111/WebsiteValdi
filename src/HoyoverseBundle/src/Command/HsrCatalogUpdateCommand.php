<?php

namespace App\HoyoverseBundle\Command;

use App\HoyoverseBundle\Service\Catalog\HsrCatalog;
use App\HoyoverseBundle\Service\Catalog\HsrCatalogUpdater;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Manual catalog update; the weekly update runs as the "hsr_catalog_update" task of the hoyoverse schedule.
 */
#[AsCommand(name: 'hoyoverse:hsr-catalog:update', description: 'Rebuild the Honkai: Star Rail banner catalog (phases and rate-ups) from irminsul.gg')]
class HsrCatalogUpdateCommand extends AbstractIrminsulCatalogUpdateCommand
{
    public function __construct(HsrCatalogUpdater $updater, HsrCatalog $catalog)
    {
        parent::__construct($updater, $catalog);
    }
}

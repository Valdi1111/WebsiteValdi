<?php

namespace App\HoyoverseBundle\Command;

use App\HoyoverseBundle\Service\Catalog\ZzzCatalog;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalogUpdater;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Manual catalog update; the weekly update runs as the "zzz_catalog_update" task of the hoyoverse schedule.
 */
#[AsCommand(name: 'hoyoverse:zzz-catalog:update', description: 'Rebuild the Zenless Zone Zero banner catalog (phases and rate-ups) from irminsul.gg')]
class ZzzCatalogUpdateCommand extends AbstractIrminsulCatalogUpdateCommand
{
    public function __construct(ZzzCatalogUpdater $updater, ZzzCatalog $catalog)
    {
        parent::__construct($updater, $catalog);
    }
}

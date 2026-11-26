<?php

namespace App\HoyoverseBundle\Service\Catalog;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Rebuilds the Genshin Impact banner catalog (hoyoverse:gi-catalog:update and the gi_catalog_update task).
 */
class GiCatalogUpdater extends AbstractIrminsulCatalogUpdater
{
    public function __construct(
        GiCatalogBuilder $builder,
        GiCatalog $catalog,
        #[Target('hoyoverse.gi')]
        LoggerInterface $logger,
    ) {
        parent::__construct($builder, $catalog, $logger);
    }
}

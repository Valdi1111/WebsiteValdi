<?php

namespace App\HoyoverseBundle\Service\Catalog;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Rebuilds the Zenless Zone Zero banner catalog (hoyoverse:zzz-catalog:update and the zzz_catalog_update task).
 */
class ZzzCatalogUpdater extends AbstractIrminsulCatalogUpdater
{
    public function __construct(
        ZzzCatalogBuilder $builder,
        ZzzCatalog $catalog,
        #[Target('hoyoverse.zzz')]
        LoggerInterface $logger,
    ) {
        parent::__construct($builder, $catalog, $logger);
    }
}

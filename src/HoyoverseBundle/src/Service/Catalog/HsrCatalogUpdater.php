<?php

namespace App\HoyoverseBundle\Service\Catalog;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Rebuilds the Honkai: Star Rail banner catalog (hoyoverse:hsr-catalog:update and the hsr_catalog_update task).
 */
class HsrCatalogUpdater extends AbstractIrminsulCatalogUpdater
{
    public function __construct(
        HsrCatalogBuilder $builder,
        HsrCatalog $catalog,
        #[Target('hoyoverse.hsr')]
        LoggerInterface $logger,
    ) {
        parent::__construct($builder, $catalog, $logger);
    }
}

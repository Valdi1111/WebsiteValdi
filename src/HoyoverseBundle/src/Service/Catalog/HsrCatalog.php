<?php

namespace App\HoyoverseBundle\Service\Catalog;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Honkai: Star Rail banner catalog (irminsul.gg), rebuilt by hoyoverse:hsr-catalog:update.
 */
class HsrCatalog extends AbstractIrminsulCatalog
{
    public const string CACHE_KEY = 'hsr_catalog_v1';

    public function __construct(
        #[Target('hoyoverse.hsr_catalog.cache')]
        CacheItemPoolInterface $cache,
    ) {
        parent::__construct($cache);
    }

    public function getCacheKey(): string
    {
        return self::CACHE_KEY;
    }

    public static function getGameSlug(): string
    {
        return 'hsr';
    }
}

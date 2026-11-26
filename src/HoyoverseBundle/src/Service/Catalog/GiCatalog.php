<?php

namespace App\HoyoverseBundle\Service\Catalog;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Genshin Impact banner catalog (irminsul.gg), rebuilt by hoyoverse:gi-catalog:update.
 */
class GiCatalog extends AbstractIrminsulCatalog
{
    public const string CACHE_KEY = 'gi_catalog_v1';

    public function __construct(
        #[Target('hoyoverse.gi_catalog.cache')]
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
        return 'genshin';
    }
}

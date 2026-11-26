<?php

namespace App\HoyoverseBundle\Service\Catalog;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Zenless Zone Zero banner catalog (irminsul.gg), rebuilt by hoyoverse:zzz-catalog:update.
 */
class ZzzCatalog extends AbstractIrminsulCatalog
{
    public const string CACHE_KEY = 'zzz_catalog_v1';

    public function __construct(
        #[Target('hoyoverse.zzz_catalog.cache')]
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
        return 'zzz';
    }
}

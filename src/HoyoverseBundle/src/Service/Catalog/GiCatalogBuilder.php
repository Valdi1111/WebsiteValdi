<?php

namespace App\HoyoverseBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use App\GamesBundle\Service\Irminsul\IrminsulTimeRules;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Genshin Impact banner catalog builder: the second half of a version starts at 18:00 server time.
 */
class GiCatalogBuilder extends AbstractIrminsulCatalogBuilder
{
    public function __construct(
        IrminsulClient $client,
        IrminsulPhaseNormalizer $normalizer,
        #[Target('hoyoverse.gi')]
        LoggerInterface $logger,
    ) {
        parent::__construct($client, $normalizer, $logger);
    }

    public function getGameName(): string
    {
        return 'Genshin Impact';
    }

    protected function getGameSlug(): string
    {
        return GiCatalog::getGameSlug();
    }

    protected function getTimeRules(): IrminsulTimeRules
    {
        return IrminsulTimeRules::hoyoverse('18:00:00');
    }
}

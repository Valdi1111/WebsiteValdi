<?php

namespace App\HoyoverseBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use App\GamesBundle\Service\Irminsul\IrminsulTimeRules;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Honkai: Star Rail banner catalog builder: the second half of a version starts at 12:00 server time.
 */
class HsrCatalogBuilder extends AbstractIrminsulCatalogBuilder
{
    public function __construct(
        IrminsulClient $client,
        IrminsulPhaseNormalizer $normalizer,
        #[Target('hoyoverse.hsr')]
        LoggerInterface $logger,
    ) {
        parent::__construct($client, $normalizer, $logger);
    }

    public function getGameName(): string
    {
        return 'Honkai: Star Rail';
    }

    protected function getGameSlug(): string
    {
        return HsrCatalog::getGameSlug();
    }

    protected function getTimeRules(): IrminsulTimeRules
    {
        return IrminsulTimeRules::hoyoverse('12:00:00');
    }
}

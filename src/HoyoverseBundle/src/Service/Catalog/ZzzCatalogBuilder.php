<?php

namespace App\HoyoverseBundle\Service\Catalog;

use App\GamesBundle\Service\Irminsul\IrminsulClient;
use App\GamesBundle\Service\Irminsul\IrminsulPhaseNormalizer;
use App\GamesBundle\Service\Irminsul\IrminsulTimeRules;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Zenless Zone Zero banner catalog builder: the second half of a version starts at 12:00 server time.
 */
class ZzzCatalogBuilder extends AbstractIrminsulCatalogBuilder
{
    public function __construct(
        IrminsulClient $client,
        IrminsulPhaseNormalizer $normalizer,
        #[Target('hoyoverse.zzz')]
        LoggerInterface $logger,
    ) {
        parent::__construct($client, $normalizer, $logger);
    }

    public function getGameName(): string
    {
        return 'Zenless Zone Zero';
    }

    protected function getGameSlug(): string
    {
        return ZzzCatalog::getGameSlug();
    }

    protected function getTimeRules(): IrminsulTimeRules
    {
        return IrminsulTimeRules::hoyoverse('12:00:00');
    }
}

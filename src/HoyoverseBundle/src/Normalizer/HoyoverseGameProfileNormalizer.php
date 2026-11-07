<?php

namespace App\HoyoverseBundle\Normalizer;

use App\CoreBundle\Model\Game\HasDailiesInterface;
use App\CoreBundle\Model\Game\HasStaminaInterface;
use App\CoreBundle\Model\Game\HasWeekliesInterface;
use App\CoreBundle\Normalizer\AbstractGameProfileNormalizer;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasHilichurlInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\Game\HasMimoInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class HoyoverseGameProfileNormalizer extends AbstractGameProfileNormalizer
{
    public const array CONDITIONAL_SETTINGS = [
        HasHoyolabCheckInInterface::class => ['hoyolabCheckIn', 'hoyolabMissedCheckIn'],
        HasCodeRedemptionInterface::class => ['codeRedeem'],
        HasStaminaInterface::class => ['staminaCheck', 'staminaThreshold'],
        HasExpeditionsInterface::class => ['expeditionsCheck'],
        HasRealmCurrencyInterface::class => ['realmCurrencyCheck', 'realmCurrencyThreshold'],
        HasShopStatusInterface::class => ['shopStatusCheck'],
        HasMimoInterface::class => ['mimoCheck', 'mimoRedeem', 'mimoRedeemDraw', 'mimoLottery', 'mimoReservePoints'],
        HasHilichurlInterface::class => ['hilichurlCheck', 'hilichurlRedeem'],
        HasDailiesInterface::class => ['dailiesCheck'],
        HasWeekliesInterface::class => ['weekliesCheck'],
        HasDiaryInterface::class => ['syncDiary'],
    ];

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     * @param array<string, string>                     $timezones
     */
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        NormalizerInterface&DenormalizerInterface $objectNormalizer,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        ServiceCollectionInterface $locatorByGameId,
        #[Autowire(param: 'hoyoverse.timezones')]
        private readonly array $timezones = [],
    ) {
        parent::__construct($objectNormalizer, $locatorByGameId);
    }

    protected function getSupportedClass(): string
    {
        return HoyoverseGameProfile::class;
    }

    protected function getConditionalSettings(): array
    {
        return self::CONDITIONAL_SETTINGS;
    }

    protected function getTimezoneMap(): array
    {
        return $this->timezones;
    }
}

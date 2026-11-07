<?php

namespace App\GryphlineBundle\Normalizer;

use App\CoreBundle\Model\Game\HasDailiesInterface;
use App\CoreBundle\Model\Game\HasStaminaInterface;
use App\CoreBundle\Model\Game\HasWeekliesInterface;
use App\CoreBundle\Normalizer\AbstractGameProfileNormalizer;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasCheckInInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class GryphlineGameProfileNormalizer extends AbstractGameProfileNormalizer
{
    public const array CONDITIONAL_SETTINGS = [
        HasCheckInInterface::class => ['skportCheckIn', 'skportMissedCheckIn'],
        HasStaminaInterface::class => ['staminaCheck', 'staminaThreshold'],
        HasDailiesInterface::class => ['dailiesCheck'],
        HasWeekliesInterface::class => ['weekliesCheck'],
    ];

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     * @param array<string, string>                     $timezones
     */
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        NormalizerInterface&DenormalizerInterface $objectNormalizer,
        #[AutowireLocator(services: 'gryphline.game.id', indexAttribute: 'key')]
        ServiceCollectionInterface $locatorByGameId,
        #[Autowire(param: 'gryphline.timezones')]
        private readonly array $timezones = [],
    ) {
        parent::__construct($objectNormalizer, $locatorByGameId);
    }

    protected function getSupportedClass(): string
    {
        return GryphlineGameProfile::class;
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

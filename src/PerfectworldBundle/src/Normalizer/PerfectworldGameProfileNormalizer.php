<?php

namespace App\PerfectworldBundle\Normalizer;

use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\GamesBundle\Normalizer\AbstractGameProfileNormalizer;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Game\HasPullTrackerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Perfect World game profiles expose no automation settings: only "active" is writable.
 * The pull tracker is fed by manual imports only (no sync).
 */
class PerfectworldGameProfileNormalizer extends AbstractGameProfileNormalizer
{
    /**
     * notificationPlatforms is part of the base writable fields but hidden for this provider (no notifications yet).
     */
    public const array BASE_WRITABLE_FIELDS = ['active'];

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        NormalizerInterface&DenormalizerInterface $objectNormalizer,
        #[AutowireLocator(services: 'perfectworld.game.id', indexAttribute: 'key')]
        ServiceCollectionInterface $locatorByGameId,
        #[Autowire(lazy: true)]
        ?GameProfileStatusCollector $statusCollector = null,
    ) {
        parent::__construct($objectNormalizer, $locatorByGameId, $statusCollector);
    }

    protected function getSupportedClass(): string
    {
        return PerfectworldGameProfile::class;
    }

    protected function getConditionalSettings(): array
    {
        return [];
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $result = parent::normalize($data, $format, $context);

        if (is_array($result) && $data instanceof PerfectworldGameProfile) {
            $gameId = (int) $data->getGameId();
            $gameService = $this->locatorByGameId->has($gameId) ? $this->locatorByGameId->get($gameId) : null;
            $result['supports_pulls'] = $gameService instanceof HasPullTrackerInterface;
            $result['supports_manual_pulls'] = $gameService instanceof HasManualPullsInterface;
            $result['supports_pull_sync'] = false;
        }

        return $result;
    }
}

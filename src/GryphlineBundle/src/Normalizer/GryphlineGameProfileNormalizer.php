<?php

namespace App\GryphlineBundle\Normalizer;

use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Normalizer\AbstractGameProfileNormalizer;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Status\GryphlineProfileSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class GryphlineGameProfileNormalizer extends AbstractGameProfileNormalizer
{

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
        #[Autowire(lazy: true)]
        ?GameProfileStatusCollector $statusCollector = null,
    ) {
        parent::__construct($objectNormalizer, $locatorByGameId, $statusCollector);
    }

    protected function getSupportedClass(): string
    {
        return GryphlineGameProfile::class;
    }

    protected function getConditionalSettings(): array
    {
        // The fields of each capability come from the settings of the provider, their single definition
        return ProfileSetting::fieldsByCapability(GryphlineProfileSettings::all());
    }

    protected function getTimezoneMap(): array
    {
        return $this->timezones;
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $result = parent::normalize($data, $format, $context);

        if (is_array($result) && $data instanceof GryphlineGameProfile) {
            $gameId = (int) $data->getGameId();
            if ($this->locatorByGameId->has($gameId)) {
                $gameService = $this->locatorByGameId->get($gameId);
                // Every Gryphline pull tracker is synced automatically through the account token
                $result['supports_pulls'] = $gameService instanceof HasAutoPullImportInterface;
                // Manual S pulls and declared pities (missing history)
                $result['supports_manual_pulls'] = $gameService instanceof HasManualPullsInterface;
                $result['supports_pull_sync'] = $gameService instanceof HasAutoPullImportInterface;
                // Game-specific name of pulls (e.g. "Headhunts"), used for UI labels
                $result['pull_label'] = $gameService instanceof HasAutoPullImportInterface
                    ? $gameService->getRarityConfig()->getPullLabel()
                    : null;
            }
        }

        return $result;
    }
}

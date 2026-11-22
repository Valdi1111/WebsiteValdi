<?php

namespace App\HoyoverseBundle\Normalizer;

use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\Game\HasManualPullsInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Normalizer\AbstractGameProfileNormalizer;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasHilichurlInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\Game\HasMimoInterface;
use App\HoyoverseBundle\Model\Game\HasPowershellPullImportInterface;
use App\HoyoverseBundle\Model\Game\HasPullTrackerInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Service\Pull\PullSyncSourceResolver;
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
        HasStokenPullImportInterface::class => ['syncPulls'],
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
        // Lazy: the resolver parses cookies through the serializer, which owns this normalizer
        #[Autowire(lazy: true)]
        private readonly PullSyncSourceResolver $pullSyncSourceResolver,
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

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $result = parent::normalize($data, $format, $context);

        if (is_array($result) && $data instanceof HoyoverseGameProfile) {
            $gameId = (int) $data->getGameId();
            if ($this->locatorByGameId->has($gameId)) {
                $gameService = $this->locatorByGameId->get($gameId);
                $result['supports_pulls'] = $gameService instanceof HasPullTrackerInterface;
                // Missing pulls (manual S pulls, declared pities) can be added to the pull tracker
                $result['supports_manual_pulls'] = $gameService instanceof HasManualPullsInterface;
                // Server-side automatic sync, depending on the game and the account cookie: stoken_v2 or, where the
                // game supports it, the HoYoLAB cookies (battle chronicle); shared flag name across providers
                $pullSyncSource = $this->pullSyncSourceResolver->resolveForProfile($gameService, $data);
                $result['supports_pull_sync'] = $pullSyncSource !== null;
                $result['pull_sync_source'] = $pullSyncSource?->value;
                $result['supports_powershell_pulls'] = $gameService instanceof HasPowershellPullImportInterface;
                // Game-specific name of pulls (e.g. "Wishes", "Signals"), used for UI labels
                $result['pull_label'] = $gameService instanceof HasPullTrackerInterface
                    ? $gameService->getRarityConfig()->getPullLabel()
                    : null;
            }
        }

        return $result;
    }
}

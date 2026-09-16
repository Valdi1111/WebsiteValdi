<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\CodesRedeemMessage;
use App\HoyoverseBundle\Message\GameProfileCodeRedeemMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasAutoCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use App\HoyoverseBundle\Model\RedeemableCode;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

#[AsMessageHandler]
class CodesRedeemMessageHandler
{
    private int $totalDispatched = 0;

    /**
     * @param HoyoverseGameProfileRepository $gameProfileRepository
     * @param ServiceCollectionInterface<GameInterface&HasCodeRedemptionInterface> $locator
     * @param LoggerInterface $hoyoverseLogger
     */
    public function __construct(
        private readonly HoyoverseGameProfileRepository $gameProfileRepository,
        #[AutowireLocator(services: 'hoyoverse.game.redeemable')]
        private readonly ServiceCollectionInterface     $locator,
        private readonly LoggerInterface                $hoyoverseLogger,
        private readonly UnifiedNotificationService     $notificationService,
        private readonly CacheItemPoolInterface         $hoyoverseRedemptionHistoryCache,
        private readonly MessageBusInterface            $bus,
    )
    {
    }

    public function getLogger(): LoggerInterface
    {
        return $this->hoyoverseLogger;
    }

    public function getCache(): CacheItemPoolInterface
    {
        return $this->hoyoverseRedemptionHistoryCache;
    }

    public function __invoke(CodesRedeemMessage $message): void
    {
        $this->totalDispatched = 0;

        $this->getLogger()->info(sprintf(
            'Starting task "%s" for %d eligible game service(s).',
            static::class,
            $this->locator->count()
        ));

        foreach ($this->locator->getProvidedServices() as $serviceId => $serviceClass) {
            $gameService = $this->locator->get($serviceId);
            $this->handleGameTask($gameService, $message);
        }

        $this->getLogger()->info(sprintf(
            'Task "%s" completed. Dispatched %d code redeem message(s).',
            static::class,
            $this->totalDispatched
        ));
    }

    protected function handleGameTask(GameInterface&HasCodeRedemptionInterface $gameService, CodesRedeemMessage $message): void
    {
        try {
            $redeemableCodes = $gameService->fetchRedeemableCodes();
            if (empty($redeemableCodes)) {
                return;
            }

            $featureField = $message->getFeatureFlagField();
            $gameProfiles = $this->gameProfileRepository->findEligibleProfiles($featureField, gameId: $gameService::getGameId());

            // Manual redeem: global notification
            if (!$gameService instanceof HasAutoCodeRedemptionInterface) {
                $this->handleManualCodes($gameService, $redeemableCodes, $gameProfiles);
                return;
            }

            // Auto redeem: fetch active game profiles for this game service
            /** @var GameInterface&HasAutoCodeRedemptionInterface $gameService */
            $this->handleAutoCodes($gameService, $redeemableCodes, $gameProfiles);
        } catch (\Throwable $e) {
            $this->getLogger()->error(sprintf(
                'Error processing game  %s in task "%s": %s',
                $gameService->getGameName(),
                static::class,
                $e->getMessage()
            ), [
                'exception' => $e,
                'game' => $gameService->getGameName(),
            ]);
        }
    }

    /**
     * @param GameInterface&HasCodeRedemptionInterface $gameService
     * @param RedeemableCode[] $redeemableCodes
     * @param HoyoverseGameProfile[] $gameProfiles
     * @return void
     */
    private function handleManualCodes(GameInterface&HasCodeRedemptionInterface $gameService, array $redeemableCodes, array $gameProfiles): void
    {
        foreach ($redeemableCodes as $redeemableCode) {
            $cacheKey = sprintf('manual_redeemed_%d_%s', $gameService::getGameId(), $redeemableCode->getCode());
            if ($this->getCache()->hasItem($cacheKey)) {
                continue;
            }

            foreach ($gameProfiles as $gameProfile) {
                $notification = $this->createNotificationWithGameProfile($gameProfile)
                    ->setDescription("Code Found - Manual Redemption Required\n{$gameService->getCodeRedemptionManualReason()}")
                    ->addField("Code", $redeemableCode->getCode(), icon: "💰", inline: true)
                    ->addField("Rewards", implode("\n", $redeemableCode->getRewards()), inline: true);

                $this->notificationService->sendToUser($gameProfile->getAccount()->getUser(), $notification, $gameProfile->getNotificationPlatforms());
            }

            $cacheItem = $this->getCache()->getItem($cacheKey);
            $cacheItem->set(true);
            $this->getCache()->save($cacheItem);
        }
    }

    /**
     * @param GameInterface&HasAutoCodeRedemptionInterface $gameService
     * @param RedeemableCode[] $redeemableCodes
     * @param HoyoverseGameProfile[] $gameProfiles
     * @return void
     */
    private function handleAutoCodes(GameInterface&HasAutoCodeRedemptionInterface $gameService, array $redeemableCodes, array $gameProfiles): void
    {
        foreach ($gameProfiles as $gameProfile) {
            $profileId = $gameProfile->getId();

            foreach ($redeemableCodes as $redeemableCode) {
                $redeemedKey = sprintf('redeemed_%d_%s', $profileId, $redeemableCode->getCode());
                $pendingKey = sprintf('pending_%d_%s', $profileId, $redeemableCode->getCode());

                // 1. Già riscattato definitivamente? (Permanente)
                if ($this->getCache()->hasItem($redeemedKey)) {
                    continue;
                }

                // 2. Già in coda? (Lock temporaneo)
                if ($this->getCache()->hasItem($pendingKey)) {
                    continue;
                }

                // 3. Metti il lock "in coda" (30 minuti di TTL di sicurezza contro i crash)
                $pendingItem = $this->getCache()->getItem($pendingKey);
                $pendingItem->set(true);
                $pendingItem->expiresAfter(1800);
                $this->getCache()->save($pendingItem);

                // Dispatch immediato: il rate limiting sarà gestito direttamente dal worker
                $this->bus->dispatch(
                    new GameProfileCodeRedeemMessage($profileId, $redeemableCode)
                );

                $this->totalDispatched++;
            }
        }
    }

    public function createNotificationWithGameProfile(HoyoverseGameProfile $gameProfile): UniversalEmbed
    {
        return new UniversalEmbed()
            ->setTitle(sprintf("%s - %s", $gameProfile->getGameName(), "Code Redeem"))
            ->setColor(0x3498DB)
            ->setFooterText($gameProfile->getGameName())
            ->setFooterIconUrl($gameProfile->getIconUrl())
            ->setTimestamp(new \DateTimeImmutable())
            ->addField("Player", sprintf("(`%s`) %s", $gameProfile->getGameUid(), $gameProfile->getNickname()), icon: "👤", inline: true)
            ->addField("Region", $gameProfile->getParsedRegion(), icon: "🌍", inline: true)
            ->addField("Rank", $gameProfile->getLevel(), icon: "🏆", inline: true);
    }
}
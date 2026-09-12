<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\FeatureFlagMessageInterface;
use App\HoyoverseBundle\Message\GameProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\RegionalTaskMessageInterface;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * @template T of TaskMessageInterface
 */
abstract class AbstractTaskMessageHandler
{

    /**
     * @param HoyoverseGameProfileRepository $gameProfileRepository
     * @param HoyolabCookieUtilsService $cookieUtils
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     * @param LoggerInterface $hoyoverseLogger
     */
    public function __construct(
        protected readonly HoyoverseGameProfileRepository $gameProfileRepository,
        protected readonly HoyolabCookieUtilsService      $cookieUtils,
        #[AutowireLocator(services: 'hoyoverse.game', defaultIndexMethod: 'getGameId')]
        protected readonly ServiceCollectionInterface     $locatorByGameId,
        protected readonly LoggerInterface                $hoyoverseLogger,
        protected readonly UnifiedNotificationService     $notificationService,
    )
    {
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->hoyoverseLogger;
    }

    /**
     * Executes the task logic for a single eligible game profile.
     *
     * @param GameInterface $gameService
     * @param RuntimeAccountData $accountData
     * @param T $message
     */
    abstract protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void;

    /**
     * Main dispatch method called by child handlers.
     *
     * @param T $message
     */
    protected function handleTask(TaskMessageInterface $message): void
    {
        if ($message instanceof GameProfileTaskMessageInterface) {
            $this->getLogger()->info(sprintf(
                'Starting task "%s" for  profile ID %d.',
                static::class,
                $message->getGameProfileId()
            ));

            $gameProfile = $this->gameProfileRepository->find($message->getGameProfileId());
            if (!$gameProfile) {
                $this->getLogger()->warning(sprintf(
                    'Error processing profile ID %d in task "%s": Game profile not found.',
                    $message->getGameProfileId(),
                    static::class,
                ));
                return;
            }

            $this->handleProfileTask($gameProfile, $message);

            $this->getLogger()->info(sprintf('Task "%s" completed.', static::class));
            return;
        }

        $timezone = null;
        if ($message instanceof RegionalTaskMessageInterface) {
            $timezone = $message->getTimezone();
        }

        $featureField = null;
        if ($message instanceof FeatureFlagMessageInterface) {
            $featureField = $message->getFeatureFlagField();
        }

        $gameProfiles = $this->gameProfileRepository->findEligibleProfiles($featureField, timezone: $timezone);

        $this->getLogger()->info(sprintf(
            'Starting task "%s" for %d eligible profile(s)%s.',
            static::class,
            count($gameProfiles),
            $timezone ? " in timezone [{$timezone}]" : ''
        ));

        foreach ($gameProfiles as $gameProfile) {
            $this->handleProfileTask($gameProfile, $message);
        }

        $this->getLogger()->info(sprintf('Task "%s" completed.', static::class));
    }

    /**
     * Main dispatch method for a profile.
     *
     * @param T $message
     */
    protected function handleProfileTask(HoyoverseGameProfile $gameProfile, TaskMessageInterface $message): void
    {
        try {
            if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
                $this->getLogger()->warning(sprintf(
                    'No service registered in locator for game_id "%d" (Profile ID: %d). Skipping.',
                    $gameProfile->getGameId(),
                    $gameProfile->getId()
                ));
                return;
            }

            $parsedCookie = $this->cookieUtils->parseCookie($gameProfile->getAccount()->getCookie());
            $accountData = new RuntimeAccountData()
                ->setParsedCookie($parsedCookie)
                ->setGameProfile($gameProfile);

            $gameService = $this->locatorByGameId->get($gameProfile->getGameId());
            $this->processProfile($gameService, $accountData, $message);
        } catch (\Throwable $e) {
            $this->getLogger()->error(sprintf(
                'Error processing profile ID %d (UID: %s, Game: %s) in task "%s": %s',
                $gameProfile->getId(),
                $gameProfile->getGameUid(),
                $gameProfile->getGameName(),
                static::class,
                $e->getMessage()
            ), [
                'exception' => $e,
                'game_profile_id' => $gameProfile->getId(),
            ]);
        }
    }

    public function createNotificationWithAccountData(RuntimeAccountData $accountData, string $title): UniversalEmbed
    {
        $gameProfile = $accountData->getGameProfile();
        return new UniversalEmbed()
            ->setTitle(sprintf("%s - %s", $gameProfile->getGameName(), $title))
            ->setColor(0x3498DB)
            ->setFooterText($gameProfile->getGameName())
            ->setFooterIconUrl($gameProfile->getIconUrl())
            ->setTimestamp(new \DateTimeImmutable())
            ->addField("Player", sprintf("(`%s`) %s", $gameProfile->getGameUid(), $gameProfile->getNickname()), icon: "👤", inline: true)
            ->addField("Region", $gameProfile->getParsedRegion(), icon: "🌍", inline: true)
            ->addField("Rank", $gameProfile->getLevel(), icon: "🏆", inline: true);
    }

    public function sendNotificationToAccountData(RuntimeAccountData $accountData, UniversalEmbed $notification): void
    {
        $this->notificationService->sendToUser(
            $accountData->getUser(),
            $notification,
            $accountData->getGameProfile()->getNotificationPlatforms()
        );
    }

}
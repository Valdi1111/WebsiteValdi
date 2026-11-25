<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Exception\ConfigurationException;
use App\HoyoverseBundle\Exception\HoyolabException;
use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * @template T of ProfileTaskMessageInterface
 */
abstract class AbstractProfileTaskMessageHandler
{
    public function __construct(
        protected readonly HoyoverseGameProfileRepository $gameProfileRepository,
        protected readonly HoyolabCookieUtilsService      $cookieUtils,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        protected readonly ServiceCollectionInterface     $locatorByGameId,
        #[Target('hoyoverse')]
        protected readonly LoggerInterface                $logger,
        protected readonly UnifiedNotificationService     $notificationService,
    ) {}

    /**
     * @param T $message
     */
    abstract protected function processProfile(
        GameInterface              $gameService,
        RuntimeAccountData         $accountData,
        ProfileTaskMessageInterface $message
    ): void;

    /**
     * @param T $message
     */
    protected function handleProfile(ProfileTaskMessageInterface $message): void
    {
        $profileId = $message->getGameProfileId();
        $gameProfile = $this->gameProfileRepository->find($profileId);

        if (!$gameProfile) {
            $this->logger->warning(sprintf(
                '[%s] Game profile ID %d not found. Skipping.',
                static::class,
                $profileId
            ));
            return;
        }

        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            $this->logger->warning(sprintf(
                '[%s] No game service for game_id "%d" (Profile ID: %d). Skipping.',
                static::class,
                $gameProfile->getGameId(),
                $profileId
            ));
            return;
        }

        // The exception goes to Messenger (retries, failed messages, worker log): name the account, not the cookie
        $account = $gameProfile->getAccount();
        $parsedCookie = $this->cookieUtils->tryParseCookie($account?->getCookie())
            ?? throw ConfigurationException::invalidCookie($account?->getId());
        $accountData = new RuntimeAccountData()
            ->setParsedCookie($parsedCookie)
            ->setGameProfile($gameProfile);

        /** @var GameInterface $gameService */
        $gameService = $this->locatorByGameId->get($gameProfile->getGameId());

        try {
            $this->processProfile($gameService, $accountData, $message);
        } catch (\Throwable $e) {
            $context = [
                'task'            => static::class,
                'game_profile_id' => $gameProfile->getId(),
                'uid'             => $gameProfile->getGameUid(),
                'game'            => $gameProfile->getGameName(),
                'exception'       => $e,
            ];

            if ($e instanceof HoyolabException) {
                $context['hoyolab_retcode'] = $e->getHoyolabRetcode();
                $context['hoyolab_status']  = $e->getHoyolabStatusCode();
            }

            $this->logger->error(sprintf(
                'Error in [%s] for profile ID %d: %s',
                static::class,
                $profileId,
                $e->getMessage()
            ), $context);

            // RE-THROW THE EXCEPTION to trigger Messenger retry for this single profile
            throw $e;
        }
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

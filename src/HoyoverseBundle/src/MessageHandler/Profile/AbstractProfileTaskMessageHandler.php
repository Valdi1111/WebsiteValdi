<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Exception\HoyolabException;
use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * @template T of ProfileTaskMessageInterface
 */
abstract class AbstractProfileTaskMessageHandler
{
    public function __construct(
        protected readonly HoyoverseGameProfileRepository $gameProfileRepository,
        protected readonly HoyolabCookieUtilsService      $cookieUtils,
        #[AutowireLocator(services: 'hoyoverse.game', defaultIndexMethod: 'getGameId')]
        protected readonly ServiceCollectionInterface     $locatorByGameId,
        protected readonly LoggerInterface                $hoyoverseLogger,
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
            $this->hoyoverseLogger->warning(sprintf(
                '[%s] Game profile ID %d not found. Skipping.',
                static::class,
                $profileId
            ));
            return;
        }

        if (!$this->locatorByGameId->has($gameProfile->getGameId())) {
            $this->hoyoverseLogger->warning(sprintf(
                '[%s] No game service for game_id "%d" (Profile ID: %d). Skipping.',
                static::class,
                $gameProfile->getGameId(),
                $profileId
            ));
            return;
        }

        $parsedCookie = $this->cookieUtils->parseCookie($gameProfile->getAccount()->getCookie());
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

            $this->hoyoverseLogger->error(sprintf(
                'Error in [%s] for profile ID %d: %s',
                static::class,
                $profileId,
                $e->getMessage()
            ), $context);

            // RILANCIA L'ECCEZIONE per attivare il retry di Messenger su questo singolo profilo
            throw $e;
        }
    }

    public function createNotificationWithAccountData(RuntimeAccountData $accountData, string $title): UniversalEmbed
    {
        $gameProfile = $accountData->getGameProfile();

        return new UniversalEmbed()
            ->setTitle(sprintf('%s - %s', $gameProfile->getGameName(), $title))
            ->setColor(0x3498DB)
            ->setFooterText($gameProfile->getGameName())
            ->setFooterIconUrl($gameProfile->getIconUrl())
            ->setTimestamp(new \DateTimeImmutable())
            ->addField('Player', sprintf('(`%s`) %s', $gameProfile->getGameUid(), $gameProfile->getNickname()), icon: '👤', inline: true)
            ->addField('Region', $gameProfile->getParsedRegion(), icon: '🌍', inline: true)
            ->addField('Rank', (string) $gameProfile->getLevel(), icon: '🏆', inline: true);
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
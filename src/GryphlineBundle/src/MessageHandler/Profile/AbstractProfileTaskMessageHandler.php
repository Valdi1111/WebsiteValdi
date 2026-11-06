<?php

namespace App\GryphlineBundle\MessageHandler\Profile;

use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GryphlineBundle\Exception\GryphlineException;
use App\CoreBundle\Message\ProfileTaskMessageInterface;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
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
        protected readonly GryphlineGameProfileRepository $gameProfileRepository,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        protected readonly ServiceCollectionInterface $locatorByGameBiz,
        #[Target('gryphline')]
        protected readonly LoggerInterface $logger,
        protected readonly UnifiedNotificationService $notificationService,
    ) {
    }

    /**
     * @param T $message
     */
    abstract protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
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

        if (!$this->locatorByGameBiz->has($gameProfile->getGameBiz())) {
            $this->logger->warning(sprintf(
                '[%s] No game service for game_biz "%s" (Profile ID: %d). Skipping.',
                static::class,
                $gameProfile->getGameBiz(),
                $profileId
            ));
            return;
        }

        /** @var GameInterface $gameService */
        $gameService = $this->locatorByGameBiz->get($gameProfile->getGameBiz());

        $accountData = (new RuntimeAccountData())
            ->setGameProfile($gameProfile);

        try {
            $this->processProfile($gameService, $accountData, $message);
        } catch (\Throwable $e) {
            $context = [
                'task' => static::class,
                'game_profile_id' => $gameProfile->getId(),
                'uid' => $gameProfile->getGameUid(),
                'game' => $gameProfile->getGameName(),
                'exception' => $e,
            ];

            if ($e instanceof GryphlineException) {
                $context['api_code'] = $e->getApiCode();
                $context['api_status'] = $e->getApiStatusCode();
                $context['api_response'] = $e->getResponseBody();
            }

            $this->logger->error(sprintf(
                'Error in [%s] for profile ID %d: %s',
                static::class,
                $profileId,
                $e->getMessage()
            ), $context);

            throw $e;
        }
    }

    public function createNotificationWithAccountData(RuntimeAccountData $accountData, string $title): UniversalEmbed
    {
        $gameProfile = $accountData->getGameProfile();

        return (new UniversalEmbed())
            ->setTitle(sprintf('%s - %s', $gameProfile->getGameName(), $title))
            ->setColor(0x3498DB)
            ->setFooterText($gameProfile->getGameName())
            ->setFooterIconUrl($gameProfile->getIconUrl() ?? 'https://game.skport.com/favicon.ico')
            ->setTimestamp(new \DateTimeImmutable())
            ->addField('Player', sprintf('(`%s`) %s', $gameProfile->getGameUid(), $gameProfile->getNickname() ?? 'Player'), icon: '👤', inline: true)
            ->addField('Region', $gameProfile->getParsedRegion() ?? 'Global', icon: '🌍', inline: true)
            ->addField('Rank', (string) ($gameProfile->getLevel() ?? 1), icon: '🏆', inline: true);
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

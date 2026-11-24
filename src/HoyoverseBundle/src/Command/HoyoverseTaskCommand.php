<?php

namespace App\HoyoverseBundle\Command;

use App\GamesBundle\Command\AbstractTaskCommand;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\GamesBundle\Model\Task\ManualTask;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Batch\SyncDiaryBatchMessage;
use App\HoyoverseBundle\Message\Profile\DailiesReminderProfileMessage;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use App\HoyoverseBundle\Message\Profile\ExpeditionsCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\HoyolabCheckInProfileMessage;
use App\HoyoverseBundle\Message\Profile\HoyolabMissedCheckInProfileMessage;
use App\HoyoverseBundle\Message\Profile\RealmCurrencyCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\ShopStatusCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\StaminaCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\SyncDiaryPageProfileMessage;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * The per-profile messages skip the feature flags and the active flag: their handlers do not check them again. A
 * profile whose game lacks the capability of the task is skipped here, instead of being ignored by the handler.
 */
#[AsCommand(
    name: 'hoyoverse:task',
    description: 'Run a HoYoverse scheduled task now (check-in, codes, reminders, diary and pulls sync, cookie renewal), for every eligible profile or for given profiles'
)]
class HoyoverseTaskCommand extends AbstractTaskCommand
{
    /** Seconds between the banners of a per-profile pulls sync, as the "Sync" button of the pulls page */
    private const int PULLS_BANNER_STAGGER_SECONDS = 15;

    /** Seconds between the currencies of a diary sync, as SyncDiaryBatchHandler */
    private const int DIARY_CURRENCY_STAGGER_SECONDS = 45;

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     * @param array<string, array{enabled: bool, message_class: string, cron: ?string, regional: bool, jitter?: ?int}> $scheduledTasks
     * @param array<string, string> $regions
     */
    public function __construct(
        private readonly HoyoverseGameProfileRepository $profileRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly PullOperationTracker $operationTracker,
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        private readonly ServiceCollectionInterface $locatorByGameId,
        #[Autowire(param: 'hoyoverse.tasks')]
        private readonly array $scheduledTasks,
        #[Autowire(param: 'hoyoverse.timezones')]
        private readonly array $regions,
    ) {
        parent::__construct();
    }

    protected function getProviderKey(): string
    {
        return 'hoyoverse';
    }

    protected function getProviderName(): string
    {
        return 'HoYoverse';
    }

    protected function getScheduledTasks(): array
    {
        return $this->scheduledTasks;
    }

    protected function getRegions(): array
    {
        return $this->regions;
    }

    protected function getProfileRepository(): HoyoverseGameProfileRepository
    {
        return $this->profileRepository;
    }

    protected function getMessageBus(): MessageBusInterface
    {
        return $this->messageBus;
    }

    protected function getManualTasks(): array
    {
        // One message built from the profile id, for the games with the capability
        $when = fn (string $capability, string $messageClass): \Closure => fn (GameProfileInterface $profile): array => $this->getGame($profile) instanceof $capability
            ? [new $messageClass((int) $profile->getId())]
            : [];

        return [
            'hoyolab_check_in' => new ManualTask(
                'HoYoLAB daily check-in: claims the reward and notifies the result',
                $when(HasHoyolabCheckInInterface::class, HoyolabCheckInProfileMessage::class),
                'Notifies also when the reward was already claimed',
            ),
            'hoyolab_missed_check_in' => new ManualTask(
                'Second check-in pass: claims the reward if it is still unclaimed',
                $when(HasHoyolabCheckInInterface::class, HoyolabMissedCheckInProfileMessage::class),
                'Notifies only a reward claimed by this run',
            ),
            'code_redeem' => new ManualTask(
                'Redeem the new promo codes on every eligible profile',
                note: 'Batch only (each code is a message); HI3 and Tears of Themis only get a notification to redeem by hand',
            ),
            'stamina' => new ManualTask(
                'Stamina reminder: notifies when stamina is within the profile threshold',
                $when(HasStaminaInterface::class, StaminaCheckProfileMessage::class),
                'GI, HSR, ZZZ; game notes are cached for 5 minutes',
            ),
            'expedition' => new ManualTask(
                'Expeditions reminder: notifies when every expedition is done',
                $when(HasExpeditionsInterface::class, ExpeditionsCheckProfileMessage::class),
                'GI, HSR',
            ),
            'realm_currency' => new ManualTask(
                'Serenitea Pot reminder: notifies when the realm currency is within the threshold',
                $when(HasRealmCurrencyInterface::class, RealmCurrencyCheckProfileMessage::class),
                'GI only',
            ),
            'shop_status' => new ManualTask(
                'Video store reminder: notifies when the shop has finished selling',
                $when(HasShopStatusInterface::class, ShopStatusCheckProfileMessage::class),
                'ZZZ only',
            ),
            'mimo' => new ManualTask(
                'Traveling Mimo automation (HSR, ZZZ)',
                note: 'Not implemented yet: no game supports it and the handler does nothing',
            ),
            'hilichurl' => new ManualTask(
                'Hilichurl Machine Workshop automation (GI)',
                note: 'Not implemented yet: no game supports it and the handler does nothing',
            ),
            'sync_diary' => new ManualTask(
                'Diary sync of last month, one page chain per currency',
                $this->createDiarySyncMessages(...),
                \sprintf('Rewrites the month (page 1 deletes its stored entries first); currencies %d s apart', self::DIARY_CURRENCY_STAGGER_SECONDS),
            ),
            'sync_pulls' => new ManualTask(
                'Pulls history sync with the stoken_v2, one page chain per banner',
                $this->createPullsSyncMessages(...),
                \sprintf('GI, ZZZ; per profile: tracked like the "Sync" button, banners %d s apart', self::PULLS_BANNER_STAGGER_SECONDS),
            ),
            'update_cookie' => new ManualTask(
                'Renew the cookies of every account with an stoken_v2',
                note: 'Per account: batch only',
            ),
            'dailies_reminder' => new ManualTask(
                'Dailies reminder: notifies when dailies are left',
                $when(HasDailiesInterface::class, DailiesReminderProfileMessage::class),
            ),
            'weeklies_reminder' => new ManualTask(
                'Weeklies reminder: notifies when weeklies are left',
                $when(HasWeekliesInterface::class, WeekliesReminderProfileMessage::class),
            ),
            'endgames_reminder' => new ManualTask(
                'Endgames reminder: notifies when an endgame mode ends soon and still needs clearing',
                $when(HasEndgamesInterface::class, EndgamesReminderProfileMessage::class),
                'Zenless Zone Zero and Honkai: Star Rail, following the endgames settings of the profile',
            ),
        ];
    }

    private function getGame(GameProfileInterface $profile): ?GameInterface
    {
        \assert($profile instanceof HoyoverseGameProfile);

        $gameId = $profile->getGameId();

        return $gameId !== null && $this->locatorByGameId->has($gameId) ? $this->locatorByGameId->get($gameId) : null;
    }

    /**
     * First page of each currency of last month, as SyncDiaryBatchHandler does for one profile.
     *
     * @return list<object>
     */
    private function createDiarySyncMessages(GameProfileInterface $profile): array
    {
        $game = $this->getGame($profile);
        if (!$game instanceof HasDiaryInterface) {
            return [];
        }

        $targetDate = new SyncDiaryBatchMessage()->resolveTargetDate();
        $month = $game->getDiaryPeriod($targetDate);

        $messages = [];
        $delaySeconds = 0;
        foreach ($game->getDiaryCurrencies() as $currency) {
            $message = new SyncDiaryPageProfileMessage((int) $profile->getId(), $currency, $month, $targetDate->format('Y-m'), 1);
            $messages[] = $delaySeconds > 0 ? new Envelope($message, [new DelayStamp($delaySeconds * 1000)]) : $message;
            $delaySeconds += self::DIARY_CURRENCY_STAGGER_SECONDS;
        }

        return $messages;
    }

    /**
     * Starts a tracked sync operation and returns the first page message of each banner, as the pulls "Sync" button.
     *
     * @return list<object>
     */
    private function createPullsSyncMessages(GameProfileInterface $profile): array
    {
        $game = $this->getGame($profile);
        if (!$game instanceof HasStokenPullImportInterface) {
            return [];
        }

        $bannerTypes = $game->getGachaConfig()->getBannerTypes();
        $operation = $this->operationTracker->startSyncOperation((int) $profile->getId(), null, PullOperationKind::STOKEN_SYNC, $bannerTypes);

        $messages = [];
        $delaySeconds = 0;
        foreach (array_keys($bannerTypes) as $gachaType) {
            $message = new SyncPullsPageProfileMessage((int) $profile->getId(), (string) $gachaType, '0', null, [], (string) $operation['id']);
            $messages[] = $delaySeconds > 0 ? new Envelope($message, [new DelayStamp($delaySeconds * 1000)]) : $message;
            $delaySeconds += self::PULLS_BANNER_STAGGER_SECONDS;
        }

        return $messages;
    }
}

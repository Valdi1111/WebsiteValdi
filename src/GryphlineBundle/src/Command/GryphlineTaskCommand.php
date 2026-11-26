<?php

namespace App\GryphlineBundle\Command;

use App\GamesBundle\Command\AbstractTaskCommand;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Pull\PullOperationKind;
use App\GamesBundle\Model\Task\ManualTask;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Message\Profile\DailiesReminderProfileMessage;
use App\GryphlineBundle\Message\Profile\SkportCheckInProfileMessage;
use App\GryphlineBundle\Message\Profile\SkportMissedCheckInProfileMessage;
use App\GryphlineBundle\Message\Profile\StaminaCheckProfileMessage;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Service\Pull\PullOperationTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The per-profile messages skip the feature flags and the active flag: their handlers do not check them again.
 */
#[AsCommand(
    name: 'gryphline:task',
    description: 'Run a Gryphline scheduled task now (check-in, stamina, reminders, pulls sync, catalog), for every eligible profile or for given profiles'
)]
class GryphlineTaskCommand extends AbstractTaskCommand
{
    /** Seconds between the banners of a per-profile pulls sync, as the "Sync" button of the pulls page */
    private const int PULLS_BANNER_STAGGER_SECONDS = 15;

    /**
     * @param array<string, array{enabled: bool, message_class: string, cron: ?string, regional: bool, jitter?: ?int}> $scheduledTasks
     * @param array<string, string> $regions
     * @param ServiceLocator<GameInterface> $locatorByGameBiz
     */
    public function __construct(
        private readonly GryphlineGameProfileRepository $profileRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly PullOperationTracker $operationTracker,
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        private readonly ServiceLocator $locatorByGameBiz,
        #[Autowire(param: 'gryphline.tasks')]
        private readonly array $scheduledTasks,
        #[Autowire(param: 'gryphline.timezones')]
        private readonly array $regions,
    ) {
        parent::__construct();
    }

    protected function getProviderKey(): string
    {
        return 'gryphline';
    }

    protected function getProviderName(): string
    {
        return 'Gryphline';
    }

    protected function getScheduledTasks(): array
    {
        return $this->scheduledTasks;
    }

    protected function getRegions(): array
    {
        return $this->regions;
    }

    protected function getProfileRepository(): GryphlineGameProfileRepository
    {
        return $this->profileRepository;
    }

    protected function getMessageBus(): MessageBusInterface
    {
        return $this->messageBus;
    }

    protected function getManualTasks(): array
    {
        $byId = static fn (string $class): \Closure => static fn (GameProfileInterface $profile): array => [new $class((int) $profile->getId())];

        return [
            'skport_check_in' => new ManualTask(
                'SKPort daily check-in: claims the reward and notifies the result',
                $byId(SkportCheckInProfileMessage::class),
                'Notifies also when the reward was already claimed',
            ),
            'skport_missed_check_in' => new ManualTask(
                'Second check-in pass: claims the reward if it is still unclaimed',
                $byId(SkportMissedCheckInProfileMessage::class),
                'Notifies only a reward claimed by this run',
            ),
            'stamina' => new ManualTask(
                'Stamina reminder: notifies when stamina is within the profile threshold',
                $byId(StaminaCheckProfileMessage::class),
                'Game notes are cached for 5 minutes',
            ),
            'dailies_reminder' => new ManualTask(
                'Dailies reminder: notifies when dailies are left',
                $byId(DailiesReminderProfileMessage::class),
            ),
            'weeklies_reminder' => new ManualTask(
                'Weeklies reminder: notifies when weeklies are left',
                $byId(WeekliesReminderProfileMessage::class),
            ),
            'sync_pulls' => new ManualTask(
                'Headhunting history sync from SKPort, one page chain per banner',
                $this->createPullsSyncMessages(...),
                \sprintf('Per profile: tracked like the "Sync" button, banners %d s apart', self::PULLS_BANNER_STAGGER_SECONDS),
            ),
            'catalog_update' => new ManualTask(
                'Rebuild the Endfield operator and weapon catalog from AkeData',
            ),
        ];
    }

    /**
     * Starts a tracked sync operation and returns the first page message of each banner (empty when the game has no
     * automatic pulls import).
     *
     * @return list<object>
     */
    private function createPullsSyncMessages(GameProfileInterface $profile): array
    {
        \assert($profile instanceof GryphlineGameProfile);

        $gameBiz = (string) $profile->getGameBiz();
        $game = $this->locatorByGameBiz->has($gameBiz) ? $this->locatorByGameBiz->get($gameBiz) : null;
        if (!$game instanceof HasAutoPullImportInterface) {
            return [];
        }

        $bannerTypes = $game->getSyncStreams();
        $operation = $this->operationTracker->startSyncOperation((int) $profile->getId(), null, PullOperationKind::AUTO_SYNC, $bannerTypes);

        $messages = [];
        $delaySeconds = 0;
        foreach (array_keys($bannerTypes) as $category) {
            $message = new SyncPullsPageProfileMessage((int) $profile->getId(), (string) $category, null, null, (string) $operation['id']);
            $messages[] = $delaySeconds > 0 ? new Envelope($message, [new DelayStamp($delaySeconds * 1000)]) : $message;
            $delaySeconds += self::PULLS_BANNER_STAGGER_SECONDS;
        }

        return $messages;
    }
}

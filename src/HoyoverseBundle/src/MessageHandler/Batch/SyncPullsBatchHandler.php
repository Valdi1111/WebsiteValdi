<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\SyncPullsBatchMessage;
use App\HoyoverseBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\CoreBundle\Model\Pull\PullOperationKind;
use App\HoyoverseBundle\Service\Pull\PullOperationTracker;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * @implements AbstractBatchTaskMessageHandler<SyncPullsBatchMessage>
 */
#[AsMessageHandler]
class SyncPullsBatchHandler extends AbstractBatchTaskMessageHandler
{
    /**
     * Estimated seconds required to process a banner stream (e.g., ~10 pages)
     * before starting the next banner/profile stream for the same game service.
     */
    private const int BANNER_STREAM_STAGGER_SECONDS = 30;

    /**
     * Tracks the accumulated delay per game service class to keep
     * banner streams within the same title sequential while allowing
     * different games (Genshin, ZZZ) to run concurrently.
     *
     * @var array<class-string<GameInterface>, int>
     */
    private array $serviceDelays = [];

    /**
     * @var ServiceCollectionInterface<GameInterface>|null
     */
    private ?ServiceCollectionInterface $locatorByGameId = null;

    private ?PullOperationTracker $operationTracker = null;

    #[Required]
    public function setLocatorByGameId(
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        ServiceCollectionInterface $locatorByGameId
    ): void {
        $this->locatorByGameId = $locatorByGameId;
    }

    #[Required]
    public function setOperationTracker(PullOperationTracker $operationTracker): void
    {
        $this->operationTracker = $operationTracker;
    }

    public function __invoke(SyncPullsBatchMessage $message): void
    {
        // Reset state across long-running worker iterations
        $this->serviceDelays = [];

        // Execute task traversal
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        if (!$this->locatorByGameId || !$this->locatorByGameId->has($gameId)) {
            $this->logger->warning(sprintf(
                '[%s] No game service for game_id "%d" (Profile ID: %d). Skipping.',
                static::class,
                $gameId,
                $gameProfileId
            ));
            return;
        }

        $gameService = $this->locatorByGameId->get($gameId);
        // Only profiles whose game service supports automated server-side SToken pull import can run unattended
        if (!$gameService instanceof HasStokenPullImportInterface) {
            return;
        }

        $serviceClass = $gameService::class;
        $banners = $gameService->getGachaConfig()->getBannerTypes();

        $operationId = null;
        if ($this->operationTracker !== null && $banners !== []) {
            $operation = $this->operationTracker->startSyncOperation(
                $gameProfileId,
                null,
                PullOperationKind::SCHEDULED_SYNC,
                $banners
            );
            $operationId = (string) $operation['id'];
        }

        // Initialize delay offset for this specific game service if not present
        if (!isset($this->serviceDelays[$serviceClass])) {
            $this->serviceDelays[$serviceClass] = 0;
        }

        foreach (array_keys($banners) as $gachaType) {
            $stamps = [];
            $currentDelay = $this->serviceDelays[$serviceClass];

            if ($currentDelay > 0) {
                $stamps[] = new DelayStamp($currentDelay * 1000);
            }

            // Stagger next banner stream belonging to the same game
            $this->serviceDelays[$serviceClass] += self::BANNER_STREAM_STAGGER_SECONDS;

            $message = new SyncPullsPageProfileMessage(
                $gameProfileId,
                (string) $gachaType,
                '0',
                null,
                [],
                $operationId
            );

            // Yield an Envelope wrapping the message and its specific stamps
            yield new Envelope($message, $stamps);
        }
    }
}

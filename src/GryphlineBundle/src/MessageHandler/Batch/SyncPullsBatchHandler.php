<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\GryphlineBundle\Message\Batch\SyncPullsBatchMessage;
use App\GryphlineBundle\Message\Profile\SyncPullsPageProfileMessage;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
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
     * Estimated seconds required to process a pool category stream (e.g., ~10 pages)
     * before starting the next category/profile stream for the same game service.
     */
    private const int POOL_STREAM_STAGGER_SECONDS = 30;

    /**
     * Tracks the accumulated delay per game service class to keep
     * pool streams within the same title sequential while allowing
     * different games to run concurrently.
     *
     * @var array<class-string<GameInterface>, int>
     */
    private array $serviceDelays = [];

    /**
     * @var ServiceCollectionInterface<GameInterface>|null
     */
    private ?ServiceCollectionInterface $locatorByGameBiz = null;

    #[Required]
    public function setLocatorByGameBiz(
        #[AutowireLocator(services: 'gryphline.game.biz', indexAttribute: 'key')]
        ServiceCollectionInterface $locatorByGameBiz
    ): void {
        $this->locatorByGameBiz = $locatorByGameBiz;
    }

    public function __invoke(SyncPullsBatchMessage $message): void
    {
        // Reset state across long-running worker iterations
        $this->serviceDelays = [];

        // Execute task traversal
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, string $gameBiz): iterable
    {
        if (!$this->locatorByGameBiz || !$this->locatorByGameBiz->has($gameBiz)) {
            $this->logger->warning(sprintf(
                '[%s] No game service for game_biz "%s" (Profile ID: %d). Skipping.',
                static::class,
                $gameBiz,
                $gameProfileId
            ));
            return;
        }

        $gameService = $this->locatorByGameBiz->get($gameBiz);
        if (!$gameService instanceof HasAutoPullImportInterface) {
            return;
        }

        $serviceClass = $gameService::class;
        $categories = $gameService->getPullPoolCategories();

        // Initialize delay offset for this specific game service if not present
        if (!isset($this->serviceDelays[$serviceClass])) {
            $this->serviceDelays[$serviceClass] = 0;
        }

        foreach ($categories as $category) {
            $stamps = [];
            $currentDelay = $this->serviceDelays[$serviceClass];

            if ($currentDelay > 0) {
                $stamps[] = new DelayStamp($currentDelay * 1000);
            }

            // Stagger next category stream belonging to the same game
            $this->serviceDelays[$serviceClass] += self::POOL_STREAM_STAGGER_SECONDS;

            $message = new SyncPullsPageProfileMessage(
                $gameProfileId,
                $category,
                null
            );

            yield new Envelope($message, $stamps);
        }
    }
}

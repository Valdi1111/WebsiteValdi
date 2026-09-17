<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\Batch\SyncDiaryBatchMessage;
use App\HoyoverseBundle\Message\Profile\SyncDiaryPageProfileMessage;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * @implements AbstractBatchTaskMessageHandler<SyncDiaryBatchMessage>
 */
#[AsMessageHandler]
class SyncDiaryBatchHandler extends AbstractBatchTaskMessageHandler
{
    /**
     * Estimated seconds required to deplete the primary currency pages
     * before starting the secondary currency for the same game service.
     */
    private const int CURRENCY_STREAM_STAGGER_SECONDS = 45;

    /**
     * Tracks the accumulated delay per game service class to keep
     * currency streams within the same title sequential while allowing
     * different games (Genshin, HSR, ZZZ) to run concurrently.
     *
     * @var array<class-string<GameInterface>, int>
     */
    private array $serviceDelays = [];

    /**
     * @var ServiceCollectionInterface<GameInterface>|null
     */
    private ?ServiceCollectionInterface $locatorByGameId = null;

    private ?\DateTimeImmutable $targetDate = null;

    #[Required]
    public function setLocatorByGameId(
        #[AutowireLocator(services: 'hoyoverse.game', defaultIndexMethod: 'getGameId')]
        ServiceCollectionInterface $locatorByGameId
    ): void
    {
        $this->locatorByGameId = $locatorByGameId;
    }

    public function __invoke(SyncDiaryBatchMessage $message): void
    {
        // Reset state across long-running worker iterations
        $this->serviceDelays = [];

        // Pin the reference date safely to the previous month
        $this->targetDate = new \DateTimeImmutable('first day of last month');

        // Execute task traversal
        $this->dispatchBatch($message);
    }

    protected function createProfileMessages(int $gameProfileId, int $gameId): iterable
    {
        if (!$this->locatorByGameId->has($gameId)) {
            $this->hoyoverseLogger->warning(sprintf(
                '[%s] No game service for game_id "%d" (Profile ID: %d). Skipping.',
                static::class,
                $gameId,
                $gameProfileId
            ));
            return;
        }

        $gameService = $this->locatorByGameId->get($gameId);
        if (!$gameService instanceof HasDiaryInterface) {
            return;
        }

        $serviceClass = $gameService::class;
        $month = $gameService->getDiaryPeriod($this->targetDate);
        $currencies = GameDiaryCurrency::forGameService($serviceClass);

        // Initialize delay offset for this specific game service if not present
        if (!isset($this->serviceDelays[$serviceClass])) {
            $this->serviceDelays[$serviceClass] = 0;
        }

        foreach ($currencies as $currency) {
            $stamps = [];
            $currentDelay = $this->serviceDelays[$serviceClass];

            if ($currentDelay > 0) {
                $stamps[] = new DelayStamp($currentDelay * 1000);
            }

            // Stagger next currency stream belonging to the same game
            $this->serviceDelays[$serviceClass] += self::CURRENCY_STREAM_STAGGER_SECONDS;

            $message = new SyncDiaryPageProfileMessage(
                $gameProfileId,
                $currency,
                $month,
                $this->targetDate->format('Y-m'),
                1
            );

            // Yield an Envelope wrapping the message and its specific stamps
            yield new Envelope($message, $stamps);
        }
    }
}
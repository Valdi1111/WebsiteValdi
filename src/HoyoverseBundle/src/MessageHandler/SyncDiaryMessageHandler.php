<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Message\SyncDiaryMessage;
use App\HoyoverseBundle\Message\SyncDiaryPageMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractTaskMessageHandler<SyncDiaryMessage>
 */
#[AsMessageHandler]
class SyncDiaryMessageHandler extends AbstractTaskMessageHandler
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

    private ?MessageBusInterface $bus = null;
    private ?\DateTimeImmutable $targetDate = null;

    #[Required]
    public function setBus(MessageBusInterface $bus): void
    {
        $this->bus = $bus;
    }

    public function __invoke(SyncDiaryMessage $message): void
    {
        // Reset state across long-running worker iterations
        $this->serviceDelays = [];

        // Pin the reference date safely to the previous month
        $this->targetDate = new \DateTimeImmutable('first day of last month');

        // Execute task traversal
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        TaskMessageInterface $message
    ): void {
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

            $this->bus->dispatch(
                new SyncDiaryPageMessage(
                    $accountData->getGameProfile()->getId(),
                    $currency,
                    $month,
                    $this->targetDate->format('Y-m'),
                    1
                ),
                $stamps
            );

            // Stagger next currency stream belonging to the same game
            $this->serviceDelays[$serviceClass] += self::CURRENCY_STREAM_STAGGER_SECONDS;
        }
    }

}
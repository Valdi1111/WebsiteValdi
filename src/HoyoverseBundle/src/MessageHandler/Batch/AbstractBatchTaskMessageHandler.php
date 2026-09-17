<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\FeatureFlagMessageInterface;
use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\RegionalTaskMessageInterface;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @template T of TaskMessageInterface
 */
abstract class AbstractBatchTaskMessageHandler
{

    public function __construct(
        protected readonly HoyoverseGameProfileRepository $gameProfileRepository,
        protected readonly MessageBusInterface            $messageBus,
        protected readonly LoggerInterface                $hoyoverseLogger,
    )
    {
    }

    /**
     * Creates one or multiple profile tasks.
     * Can yield or return:
     * - ProfileTaskMessageInterface
     * - Envelope (if stamps like DelayStamp are needed)
     *
     * @return iterable<ProfileTaskMessageInterface|Envelope>
     */
    abstract protected function createProfileMessages(int $gameProfileId, int $gameId): iterable;

    /**
     * @param T $message
     */
    protected function dispatchBatch(TaskMessageInterface $message): void
    {
        $timezone = $message instanceof RegionalTaskMessageInterface ? $message->getTimezone() : null;
        $featureField = $message instanceof FeatureFlagMessageInterface ? $message->getFeatureFlagField() : null;

        $gameProfiles = $this->gameProfileRepository->findEligibleProfileIds($featureField, timezone: $timezone);

        $this->hoyoverseLogger->info(sprintf(
            'Batch [%s]: dispatching tasks for %d eligible profiles%s.',
            static::class,
            count($gameProfiles),
            $timezone ? " in timezone [{$timezone}]" : ''
        ));

        $dispatchedCount = 0;

        foreach ($gameProfiles as $profile) {
            $tasks = $this->createProfileMessages($profile['id'], $profile['gameId']);
            foreach ($tasks as $task) {
                // Accepts either a raw Message or a prepared Envelope with Stamps
                $this->messageBus->dispatch($task);
                $dispatchedCount++;
            }
        }

        $this->hoyoverseLogger->info(sprintf(
            'Batch [%s] completed. Dispatched %d individual messages.',
            static::class,
            $dispatchedCount
        ));
    }

}
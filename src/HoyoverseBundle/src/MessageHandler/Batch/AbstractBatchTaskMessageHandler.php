<?php

namespace App\HoyoverseBundle\MessageHandler\Batch;

use App\HoyoverseBundle\Message\FeatureFlagMessageInterface;
use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Message\RegionalTaskMessageInterface;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
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
        #[Target('hoyoverse')]
        protected readonly LoggerInterface                $logger,
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

        $this->logger->info(sprintf(
            'Batch [%s]: dispatching tasks for %d eligible profile(s)%s.',
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

        $this->logger->info(sprintf(
            'Batch [%s] completed. Dispatched %d individual message(s).',
            static::class,
            $dispatchedCount
        ));
    }

}

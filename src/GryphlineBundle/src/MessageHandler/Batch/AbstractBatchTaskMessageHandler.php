<?php

namespace App\GryphlineBundle\MessageHandler\Batch;

use App\CoreBundle\Message\FeatureFlagMessageInterface;
use App\CoreBundle\Message\ProfileTaskMessageInterface;
use App\CoreBundle\Message\RegionalTaskMessageInterface;
use App\CoreBundle\Message\TaskMessageInterface;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
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
        protected readonly GryphlineGameProfileRepository $gameProfileRepository,
        protected readonly MessageBusInterface $messageBus,
        #[Target('gryphline')]
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return iterable<ProfileTaskMessageInterface|Envelope>
     */
    abstract protected function createProfileMessages(int $gameProfileId, string $gameBiz): iterable;

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
            $tasks = $this->createProfileMessages($profile['id'], $profile['gameBiz']);
            foreach ($tasks as $task) {
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

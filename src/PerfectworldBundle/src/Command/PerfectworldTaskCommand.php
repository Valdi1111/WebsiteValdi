<?php

namespace App\PerfectworldBundle\Command;

use App\GamesBundle\Command\AbstractTaskCommand;
use App\GamesBundle\Model\Task\ManualTask;
use App\PerfectworldBundle\Repository\PerfectworldGameProfileRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Neverness to Everness has no web API for check-in, notes or pull sync: the schedule only rebuilds the item catalog.
 * Profiles are refreshed by perfectworld:fetch-game-profiles.
 */
#[AsCommand(
    name: 'perfectworld:task',
    description: 'Run a Perfect World scheduled task now (item catalog)'
)]
class PerfectworldTaskCommand extends AbstractTaskCommand
{
    /**
     * @param array<string, array{enabled: bool, message_class: string, cron: ?string, regional: bool, jitter?: ?int}> $scheduledTasks
     * @param array<string, string> $regions
     */
    public function __construct(
        private readonly PerfectworldGameProfileRepository $profileRepository,
        private readonly MessageBusInterface $messageBus,
        #[Autowire(param: 'perfectworld.tasks')]
        private readonly array $scheduledTasks,
        #[Autowire(param: 'perfectworld.timezones')]
        private readonly array $regions,
    ) {
        parent::__construct();
    }

    protected function getProviderKey(): string
    {
        return 'perfectworld';
    }

    protected function getProviderName(): string
    {
        return 'Perfect World';
    }

    protected function getScheduledTasks(): array
    {
        return $this->scheduledTasks;
    }

    protected function getRegions(): array
    {
        return $this->regions;
    }

    protected function getProfileRepository(): PerfectworldGameProfileRepository
    {
        return $this->profileRepository;
    }

    protected function getMessageBus(): MessageBusInterface
    {
        return $this->messageBus;
    }

    protected function getManualTasks(): array
    {
        return [
            'catalog_update' => new ManualTask(
                'Rebuild the Neverness to Everness item catalog from NTE_Assets and backfill the stored pulls',
                note: 'Same as perfectworld:nte-catalog:update, which also takes a --ref',
            ),
        ];
    }
}

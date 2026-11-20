<?php

namespace App\Tests\GamesBundle\Scheduler;

use App\GryphlineBundle\Message\Batch\DailiesReminderBatchMessage;
use App\GryphlineBundle\Message\Batch\StaminaCheckBatchMessage;
use App\GryphlineBundle\Message\Batch\SyncPullsBatchMessage;
use App\GryphlineBundle\Scheduler\ScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

class AbstractTaskScheduleProviderTest extends TestCase
{
    private function createProvider(array $tasks): ScheduleProvider
    {
        return new ScheduleProvider(new ParameterBag([
            'gryphline.tasks' => $tasks,
            'gryphline.timezones' => ['US_EU' => 'Europe/Paris', 'SEA' => 'Asia/Shanghai'],
        ]));
    }

    private function task(string $class, ?string $cron, bool $regional = false, bool $enabled = true): array
    {
        return ['enabled' => $enabled, 'message_class' => $class, 'cron' => $cron, 'regional' => $regional, 'jitter' => null];
    }

    private function redispatchOf(RecurringMessage $recurringMessage): RedispatchMessage
    {
        $context = new MessageContext('gryphline', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable());
        $messages = iterator_to_array($recurringMessage->getMessages($context), false);
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(RedispatchMessage::class, $messages[0]);

        return $messages[0];
    }

    public function testBuildsGlobalAndPerTimezoneRegionalMessagesOnProviderTransport(): void
    {
        $schedule = $this->createProvider([
            'stamina' => $this->task(StaminaCheckBatchMessage::class, '*/30 * * * *'),
            'dailies_reminder' => $this->task(DailiesReminderBatchMessage::class, '0 21 * * *', regional: true),
        ])->getSchedule();

        $recurringMessages = $schedule->getRecurringMessages();
        $this->assertCount(3, $recurringMessages, 'One global task + one regional task per timezone');

        $redispatches = array_map(fn (RecurringMessage $m) => $this->redispatchOf($m), $recurringMessages);
        foreach ($redispatches as $redispatch) {
            $this->assertSame('gryphline', $redispatch->transportNames);
        }

        $this->assertInstanceOf(StaminaCheckBatchMessage::class, $redispatches[0]->envelope);
        $regionKeys = array_map(
            static fn (RedispatchMessage $r) => $r->envelope->getTimezone(),
            array_slice($redispatches, 1)
        );
        $this->assertSame(['US_EU', 'SEA'], $regionKeys);
    }

    public function testSkipsDisabledTasksAndTasksWithoutCron(): void
    {
        $schedule = $this->createProvider([
            'stamina' => $this->task(StaminaCheckBatchMessage::class, '*/30 * * * *', enabled: false),
            'sync_pulls' => $this->task(SyncPullsBatchMessage::class, null),
        ])->getSchedule();

        $this->assertCount(0, $schedule->getRecurringMessages());
    }
}

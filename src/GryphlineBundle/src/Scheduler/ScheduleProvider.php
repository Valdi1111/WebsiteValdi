<?php

namespace App\GryphlineBundle\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('gryphline')]
class ScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(param: 'gryphline.timezones')]
        private readonly array $timezones,
        private readonly ParameterBagInterface $params,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

        /** @var array<string, array{enabled: bool, message_class: string, cron: ?string, regional: bool, jitter?: ?int}> $crons */
        $crons = $this->params->get('gryphline.tasks');

        foreach ($crons as $taskConfig) {
            if (!$taskConfig['enabled'] || empty($taskConfig['cron'])) {
                continue;
            }

            $messageClass = $taskConfig['message_class'];
            $cronExpression = $taskConfig['cron'];

            if ($taskConfig['regional'] ?? false) {
                // Regional task: one cron for each Timezone target
                foreach ($this->timezones as $regionKey => $timeZoneString) {
                    $schedule->add(
                        RecurringMessage::cron(
                            $cronExpression,
                            new RedispatchMessage(new $messageClass($regionKey), 'gryphline'),
                            new \DateTimeZone($timeZoneString)
                        )
                    );
                }
            } else {
                // Global task: single cron
                $schedule->add(
                    RecurringMessage::cron(
                        $cronExpression,
                        new RedispatchMessage(new $messageClass(), 'gryphline')
                    )
                );
            }
        }

        return $schedule;
    }
}

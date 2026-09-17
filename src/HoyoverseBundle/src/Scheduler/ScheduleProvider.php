<?php

namespace App\HoyoverseBundle\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('hoyoverse')]
class ScheduleProvider implements ScheduleProviderInterface
{

    public function __construct(
        #[Autowire(param: 'hoyoverse.timezones')]
        private readonly array $timezones,
        private readonly ParameterBagInterface $params
    )
    {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

        /** @var array<string, array{enabled: bool, message_class: string, cron: string, regional: bool, jitter?: int}> $crons */
        $crons = $this->params->get('hoyoverse.tasks');
        foreach ($crons as $taskConfig) {
            if (!$taskConfig['enabled']) {
                continue;
            }

            $messageClass = $taskConfig['message_class'];
            $cronExpression = $taskConfig['cron'];

            if ($taskConfig['regional']) {
                // Regional task: one cron for each Timezone target
                foreach ($this->timezones as $regionKey => $timeZoneString) {
                    $schedule->add(
                        RecurringMessage::cron(
                            $cronExpression,
                            new RedispatchMessage(new $messageClass($regionKey), 'hoyoverse'),
                            new \DateTimeZone($timeZoneString)
                        )
                    );
                }
            } else {
                // Global task: single cron
                $schedule->add(
                    RecurringMessage::cron($cronExpression, new RedispatchMessage(new $messageClass(), 'hoyoverse'))
                );
            }
        }

        return $schedule;
    }

}
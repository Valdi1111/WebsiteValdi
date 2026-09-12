<?php

namespace App\HoyoverseBundle\Scheduler;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('hoyoverse')]
class ScheduleProvider implements ScheduleProviderInterface
{
    private const array REGIONS = [
        'SEA' => 'Asia/Shanghai',
        'EU'  => 'Europe/Paris',
        'NA'  => 'America/New_York',
    ];

    public function __construct(
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
                foreach (self::REGIONS as $regionKey => $timeZoneString) {
                    $schedule->add(
                        RecurringMessage::cron(
                            $cronExpression,
                            new RedispatchMessage(new $messageClass($regionKey)),
                            new \DateTimeZone($timeZoneString)
                        )
                    );
                }
            } else {
                // Global task: single cron
                $schedule->add(
                    RecurringMessage::cron($cronExpression, new RedispatchMessage(new $messageClass()))
                );
            }
        }

        return $schedule;
    }

}
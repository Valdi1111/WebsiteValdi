<?php

namespace App\GamesBundle\Scheduler;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Builds a provider schedule from its `<provider>.tasks` and `<provider>.timezones` parameters
 * (see GameTasksConfiguration). Messages are redispatched to the transport named after the provider.
 *
 * Subclasses only declare #[AsSchedule('<provider>')] and the provider key.
 */
abstract class AbstractTaskScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        private readonly ParameterBagInterface $params,
    ) {
    }

    abstract protected function getProviderKey(): string;

    public function getSchedule(): Schedule
    {
        $providerKey = $this->getProviderKey();
        $schedule = new Schedule();

        /** @var array<string, array{enabled: bool, message_class: string, cron: ?string, regional: bool, jitter?: ?int}> $crons */
        $crons = $this->params->get($providerKey . '.tasks');
        /** @var array<string, string> $timezones region key => IANA timezone */
        $timezones = $this->params->get($providerKey . '.timezones');

        foreach ($crons as $taskConfig) {
            if (!$taskConfig['enabled'] || empty($taskConfig['cron'])) {
                continue;
            }

            $messageClass = $taskConfig['message_class'];
            $cronExpression = $taskConfig['cron'];

            if ($taskConfig['regional'] ?? false) {
                // Regional task: one cron for each timezone target
                foreach ($timezones as $regionKey => $timeZoneString) {
                    $schedule->add(
                        RecurringMessage::cron(
                            $cronExpression,
                            new RedispatchMessage(new $messageClass($regionKey), $providerKey),
                            new \DateTimeZone($timeZoneString)
                        )
                    );
                }
            } else {
                // Global task: single cron
                $schedule->add(
                    RecurringMessage::cron(
                        $cronExpression,
                        new RedispatchMessage(new $messageClass(), $providerKey)
                    )
                );
            }
        }

        return $schedule;
    }
}

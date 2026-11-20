<?php

namespace App\HoyoverseBundle\Scheduler;

use App\GamesBundle\Scheduler\AbstractTaskScheduleProvider;
use Symfony\Component\Scheduler\Attribute\AsSchedule;

#[AsSchedule('hoyoverse')]
class ScheduleProvider extends AbstractTaskScheduleProvider
{
    protected function getProviderKey(): string
    {
        return 'hoyoverse';
    }
}

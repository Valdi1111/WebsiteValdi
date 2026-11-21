<?php

namespace App\PerfectworldBundle\Scheduler;

use App\GamesBundle\Scheduler\AbstractTaskScheduleProvider;
use Symfony\Component\Scheduler\Attribute\AsSchedule;

#[AsSchedule('perfectworld')]
class ScheduleProvider extends AbstractTaskScheduleProvider
{
    protected function getProviderKey(): string
    {
        return 'perfectworld';
    }
}

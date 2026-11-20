<?php

namespace App\GryphlineBundle\Scheduler;

use App\GamesBundle\Scheduler\AbstractTaskScheduleProvider;
use Symfony\Component\Scheduler\Attribute\AsSchedule;

#[AsSchedule('gryphline')]
class ScheduleProvider extends AbstractTaskScheduleProvider
{
    protected function getProviderKey(): string
    {
        return 'gryphline';
    }
}

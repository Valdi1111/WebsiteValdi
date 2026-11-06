<?php

namespace App\Tests\CoreBundle\Model\GameNotes;

use App\CoreBundle\Model\GameNotes\GameNotesDailies;
use App\CoreBundle\Model\GameNotes\GameNotesProgressMetric;
use App\CoreBundle\Model\GameNotes\GameNotesStateMetric;
use App\CoreBundle\Model\GameNotes\GameNotesWeeklies;
use PHPUnit\Framework\TestCase;

enum DummyState: string
{
    case PENDING = 'pending';
    case DONE = 'done';
}

class GameNotesMetricsTest extends TestCase
{
    public function testProgressMetricIsDone(): void
    {
        $metric = new GameNotesProgressMetric();
        $metric->setName('Daily Activation')
            ->setCurrentValue(80)
            ->setMaxValue(100);

        $this->assertFalse($metric->isDone());

        $metric->setCurrentValue(100);
        $this->assertTrue($metric->isDone());

        $metric->setCurrentValue(120);
        $this->assertTrue($metric->isDone());
    }

    public function testStateMetricIsDone(): void
    {
        $metric = new GameNotesStateMetric();
        $metric->setName('Card Sign')
            ->setTargetValue(DummyState::DONE)
            ->setCurrentValue(DummyState::PENDING);
        $this->assertFalse($metric->isDone());

        $metric->setCurrentValue(DummyState::DONE);
        $this->assertTrue($metric->isDone());
    }

    public function testDailiesAllDone(): void
    {
        $dailies = new GameNotesDailies();
        $this->assertTrue($dailies->allDone(), 'Empty dailies list considered completed');

        $metric1 = (new GameNotesProgressMetric())->setCurrentValue(100)->setMaxValue(100);
        $metric2 = (new GameNotesProgressMetric())->setCurrentValue(50)->setMaxValue(100);

        $dailies->addDaily($metric1);
        $this->assertTrue($dailies->allDone());

        $dailies->addDaily($metric2);
        $this->assertFalse($dailies->allDone());

        $metric2->setCurrentValue(100);
        $this->assertTrue($dailies->allDone());
    }

    public function testWeekliesAllDone(): void
    {
        $weeklies = new GameNotesWeeklies();
        $this->assertTrue($weeklies->allDone(), 'Empty weeklies list considered completed');

        $metric1 = (new GameNotesProgressMetric())->setCurrentValue(7)->setMaxValue(10);
        $weeklies->addWeekly($metric1);
        $this->assertFalse($weeklies->allDone());

        $metric1->setCurrentValue(10);
        $this->assertTrue($weeklies->allDone());
    }
}

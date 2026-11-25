<?php

namespace App\Tests\GamesBundle\Model\GameNotes;

use App\CoreBundle\Model\LabeledInterface;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStateMetric;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use PHPUnit\Framework\TestCase;

enum DummyState: string
{
    case PENDING = 'pending';
    case DONE = 'done';
}

enum DummyLabeledState: string implements LabeledInterface
{
    case PENDING = 'pending';
    case DONE = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Not Yet',
            self::DONE => 'All Set',
        };
    }
}

enum DummyLevel: int
{
    case ONE = 1;
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

    public function testStateMetricWithAnUnknownCurrentValueIsNotDone(): void
    {
        $metric = new GameNotesStateMetric()->setName('Card Sign')->setTargetValue(DummyState::DONE);
        $this->assertFalse($metric->isDone());

        // Both unknown: still not done, though null === null
        $metric->setTargetValue(null);
        $this->assertFalse($metric->isDone());
    }

    public function testStateMetricFormattedOutput(): void
    {
        $metric = new GameNotesStateMetric()->setName('Card Sign')->setTargetValue(DummyLabeledState::DONE);
        $this->assertSame('Unknown', $metric->getFormattedOutput());
        $this->assertSame(GameNotesStateMetric::UNKNOWN_OUTPUT, $metric->getFormattedOutput());

        $metric->setCurrentValue(DummyLabeledState::PENDING);
        $this->assertSame('Not Yet', $metric->getFormattedOutput());

        $metric->setCurrentValue(DummyState::PENDING);
        $this->assertSame('pending', $metric->getFormattedOutput());

        $metric->setCurrentValue(DummyLevel::ONE);
        $this->assertSame('1', $metric->getFormattedOutput());
    }

    public function testDailiesWithAnUnknownStateAreNotAllDone(): void
    {
        $dailies = new GameNotesDailies()
            ->addDaily(new GameNotesProgressMetric()->setCurrentValue(100)->setMaxValue(100))
            ->addDaily(new GameNotesStateMetric()->setTargetValue(DummyState::DONE));

        $this->assertFalse($dailies->allDone());
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

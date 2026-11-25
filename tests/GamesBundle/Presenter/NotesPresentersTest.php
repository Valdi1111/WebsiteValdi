<?php

namespace App\Tests\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesMetricOutputType;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Presenter\WeekliesPresenter;
use App\GamesBundle\Service\DurationFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The presenters show the data as it is: no threshold, no "all done" check.
 */
class NotesPresentersTest extends TestCase
{
    private function profile(): GameProfileInterface
    {
        return $this->createConfiguredStub(GameProfileInterface::class, [
            'getGameName' => 'Arknights: Endfield',
            'getGameUid' => '123456789',
            'getNickname' => 'Endministrator',
            'getParsedRegion' => 'Asia',
            'getLevel' => 40,
            'getStaminaThreshold' => 300,
        ]);
    }

    private static function embedFactory(): GameProfileEmbedFactory
    {
        return new GameProfileEmbedFactory(new MockClock());
    }

    /**
     * @return list<array{0: ?string, 1: ?string, 2: ?string, 3: bool}> the data fields, player header excluded
     */
    private static function dataFields(UniversalEmbed $embed): array
    {
        return array_map(
            static fn (EmbedField $field) => [$field->getName(), $field->getValue(), $field->getIcon(), $field->isInline()],
            array_slice($embed->getFields(), 3)
        );
    }

    public function testStaminaFarFromTheThreshold(): void
    {
        $stamina = new GameNotesStamina()->setCurrentStamina(20)->setMaxStamina(360)->setStaminaRecoverTime(2 * 86400 + 125);

        $embed = new StaminaPresenter(self::embedFactory(), new DurationFormatter())->present($this->profile(), $stamina, 'Stamina');

        $this->assertSame('Arknights: Endfield - Stamina', $embed->getTitle());
        $this->assertNull($embed->getDescription());
        $this->assertSame([
            ['Stamina', '20/360', '🔋', true],
            ['Recovery Time', '2 days, 2 min, 5 sec', '🕒', true],
        ], self::dataFields($embed));
    }

    public function testStaminaRecoveryTimeCountsFromTheFullInstant(): void
    {
        // Notes fetched 4 minutes before the embed clock: the full instant wins over the relative time
        $now = new \DateTimeImmutable('2026-11-22 10:00:00', new \DateTimeZone('UTC'));
        $stamina = new GameNotesStamina()->setCurrentStamina(100)->setMaxStamina(200)
            ->setStaminaRecoverTime(3600)
            ->setFullAt($now->modify('-240 seconds')->modify('+3600 seconds'));

        $embed = new StaminaPresenter(new GameProfileEmbedFactory(new MockClock($now)), new DurationFormatter())
            ->present($this->profile(), $stamina, 'Stamina');

        $this->assertSame([
            ['Stamina', '100/200', '🔋', true],
            ['Recovery Time', '56 min, 0 sec', '🕒', true],
        ], self::dataFields($embed));
    }

    public function testStaminaWithoutRecoveryTime(): void
    {
        $stamina = new GameNotesStamina()->setCurrentStamina(360)->setMaxStamina(360);

        $embed = new StaminaPresenter(self::embedFactory(), new DurationFormatter())->present($this->profile(), $stamina, 'Stamina', 'Full');

        $this->assertSame('Full', $embed->getDescription());
        $this->assertSame([['Stamina', '360/360', '🔋', true]], self::dataFields($embed));
    }

    public function testDailiesAllDone(): void
    {
        $dailies = new GameNotesDailies()
            ->addDaily(new GameNotesProgressMetric()->setName('Activity')->setCurrentValue(100)->setMaxValue(100))
            ->addDaily(new GameNotesProgressMetric()->setName('Daily Missions')->setCurrentValue(5)->setMaxValue(5)->setOutputType(GameNotesMetricOutputType::STATUS));
        $this->assertTrue($dailies->allDone());

        $embed = new DailiesPresenter(self::embedFactory())->present($this->profile(), $dailies, 'Dailies');

        $this->assertSame('Arknights: Endfield - Dailies', $embed->getTitle());
        $this->assertSame([
            ['Activity', '100/100', '📅', true],
            ['Daily Missions', 'Completed', '📅', true],
        ], self::dataFields($embed));
    }

    public function testNoDailies(): void
    {
        $embed = new DailiesPresenter(self::embedFactory())->present($this->profile(), new GameNotesDailies(), 'Dailies');

        $this->assertSame([], self::dataFields($embed));
    }

    public function testWeekliesAllDone(): void
    {
        $weeklies = new GameNotesWeeklies()
            ->addWeekly(new GameNotesProgressMetric()->setName('Weekly Routine')->setCurrentValue(10)->setMaxValue(10))
            ->addWeekly(new GameNotesProgressMetric()->setName('Supply')->setCurrentValue(1)->setMaxValue(4)->setOutputType(GameNotesMetricOutputType::PERCENTAGE));

        $embed = new WeekliesPresenter(self::embedFactory())->present($this->profile(), $weeklies, 'Weeklies', 'This week');

        $this->assertSame('Arknights: Endfield - Weeklies', $embed->getTitle());
        $this->assertSame('This week', $embed->getDescription());
        $this->assertSame([
            ['Weekly Routine', '10/10', '📊', true],
            ['Supply', '25%', '📊', true],
        ], self::dataFields($embed));
    }
}

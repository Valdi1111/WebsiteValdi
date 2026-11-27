<?php

namespace App\Tests\GamesBundle\Chat\Fixtures;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameVariant;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\CheckInStatus;
use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use App\Tests\GamesBundle\Service\Endgame\FakeEndgamesGame;
use App\Tests\GamesBundle\Status\Fixtures\FakeNotesGameService;

/**
 * Game service with every section of the chat commands: stamina and dailies (FakeNotesGameService), weeklies, the three
 * endgame modes of FakeEndgamesGame and the check-in, recording the $fresh flag of each read.
 */
class FakeChatGameService extends FakeNotesGameService implements HasWeekliesInterface, HasEndgamesInterface, HasCheckInStatusInterface
{
    /** @var list<array{mode: string, fresh: bool}> */
    public array $endgameReads = [];

    /** @var list<bool> */
    public array $checkInReads = [];

    /** @var array<string, list<GameEndgame>|\Throwable> mode key => endgames, or the error to throw; [] = no period */
    public array $endgames = [];

    public CheckInStatus $checkIn;

    public function __construct(?\DateTimeImmutable $fetchedAt = null, int $stamina = 120)
    {
        parent::__construct($fetchedAt, $stamina);
        $this->checkIn = new CheckInStatus(true, 12, $fetchedAt);
        $this->endgames = ['first' => [self::endgame('First Mode', $fetchedAt ?? new \DateTimeImmutable())]];
    }

    /**
     * One variant, unlocked and not played, ending two days after $from.
     */
    public static function endgame(string $name, \DateTimeImmutable $from): GameEndgame
    {
        return new GameEndgame()
            ->setName($name)
            ->setVariant(EndgameVariant::DEFAULT_KEY)
            ->setUnlocked(true)
            ->setPlayed(false)
            ->setEndTime($from->modify('+2 days'))
            ->setFetchedAt($from);
    }

    public function getWeekliesData(RuntimeAccountDataInterface $account): GameNotesWeeklies
    {
        $this->getterCalls++;

        return new GameNotesWeeklies()->addWeekly(new GameNotesProgressMetric()->setName('Weekly Bosses')->setCurrentValue(1)->setMaxValue(3));
    }

    final public static function getEndgamesConfigKey(): string
    {
        return 'fake_chat';
    }

    final public static function getEndgameDefinitions(): array
    {
        return FakeEndgamesGame::getEndgameDefinitions();
    }

    public function fetchEndgame(EndgameDefinition $definition, RuntimeAccountDataInterface $account, bool $fresh = false): array
    {
        $this->endgameReads[] = ['mode' => $definition->key, 'fresh' => $fresh];

        $result = $this->endgames[$definition->key] ?? [];
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    public function getCheckInStatus(RuntimeAccountDataInterface $account, bool $fresh = false): CheckInStatus
    {
        $this->checkInReads[] = $fresh;

        return $this->checkIn;
    }
}

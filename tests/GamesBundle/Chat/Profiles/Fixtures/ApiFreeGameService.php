<?php

namespace App\Tests\GamesBundle\Chat\Profiles\Fixtures;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\EndgameLevel;
use App\GamesBundle\Model\Endgame\EndgameReminder;
use App\GamesBundle\Model\Endgame\EndgameVariant;
use App\GamesBundle\Model\Game\CheckInStatus;
use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\GameNotes\GameNotes;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use PHPUnit\Framework\Assert;

/**
 * Game service with every capability /profiles reads the settings of, whose data getters (the game API) fail the test
 * and are counted: a count stays visible even if a caller swallows the failure.
 *
 * Endgame modes: one with a single variant (default Not Played), one with a second variant (default Off), one with
 * levels.
 */
final class ApiFreeGameService implements HasStaminaInterface, HasDailiesInterface, HasEndgamesInterface, HasCheckInStatusInterface, HasPullSyncInterface
{
    /** @var list<string> the data getters called */
    public array $apiCalls = [];

    public static function getEndgamesConfigKey(): string
    {
        return 'api_free';
    }

    public static function getEndgameDefinitions(): array
    {
        return [
            new EndgameDefinition('abyss', 'Abyss'),
            new EndgameDefinition('assault', 'Assault', [
                new EndgameVariant(EndgameVariant::DEFAULT_KEY, 'Assault'),
                new EndgameVariant('hard_reminder', 'Assault (Hard)', EndgameReminder::Off),
            ]),
            new EndgameDefinition('theater', 'Theater', [
                new EndgameVariant(EndgameVariant::DEFAULT_KEY, 'Theater', levels: [
                    new EndgameLevel('easy', 'Easy'),
                    new EndgameLevel('normal', 'Normal'),
                    new EndgameLevel('visionary', 'Visionary'),
                ]),
            ]),
        ];
    }

    public function getUrlNotes(): string
    {
        return 'https://example.test/notes';
    }

    public function getNotesClass(): string
    {
        return GameNotes::class;
    }

    public function getRegenRate(): int
    {
        return 360;
    }

    public function getMaxStamina(): int
    {
        return 200;
    }

    public function getNotes(RuntimeAccountDataInterface $account, bool $fresh = false): GameNotes
    {
        $this->called(__FUNCTION__);
    }

    public function getStaminaData(RuntimeAccountDataInterface $account): GameNotesStamina
    {
        $this->called(__FUNCTION__);
    }

    public function getDailiesData(RuntimeAccountDataInterface $account): GameNotesDailies
    {
        $this->called(__FUNCTION__);
    }

    public function fetchEndgame(EndgameDefinition $definition, RuntimeAccountDataInterface $account, bool $fresh = false): array
    {
        $this->called(__FUNCTION__);
    }

    public function getCheckInStatus(RuntimeAccountDataInterface $account, bool $fresh = false): CheckInStatus
    {
        $this->called(__FUNCTION__);
    }

    private function called(string $method): never
    {
        $this->apiCalls[] = $method;
        Assert::fail(sprintf('/profiles called the game API (%s).', $method));
    }
}

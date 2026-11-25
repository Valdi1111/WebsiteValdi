<?php

namespace App\Tests\GamesBundle\Status\Fixtures;

use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\GameNotes\GameNotes;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesFetchedAtTrait;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;

/**
 * Game service with stamina and dailies from notes held in memory, counting the calls.
 */
class FakeNotesGameService implements HasStaminaInterface, HasDailiesInterface
{
    /** @var list<bool> the $fresh flag of each getNotes() call */
    public array $notesCalls = [];

    public int $getterCalls = 0;

    public ?\Throwable $notesError = null;

    public ?\Throwable $dailiesError = null;

    public function __construct(
        public ?\DateTimeImmutable $fetchedAt = null,
        public int $stamina = 120,
    ) {
    }

    public function getUrlNotes(): string
    {
        return 'https://example.test/notes';
    }

    public function getNotes(RuntimeAccountDataInterface $account, bool $fresh = false): GameNotes
    {
        $this->notesCalls[] = $fresh;
        if ($this->notesError !== null) {
            throw $this->notesError;
        }

        return new class implements GameNotes {
            use GameNotesFetchedAtTrait;
        }->setFetchedAt($this->fetchedAt);
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

    public function getStaminaData(RuntimeAccountDataInterface $account): GameNotesStamina
    {
        $this->getterCalls++;

        return new GameNotesStamina()->setCurrentStamina($this->stamina)->setMaxStamina(200)->setStaminaRecoverTime(3600);
    }

    public function getDailiesData(RuntimeAccountDataInterface $account): GameNotesDailies
    {
        $this->getterCalls++;
        if ($this->dailiesError !== null) {
            throw $this->dailiesError;
        }

        return new GameNotesDailies()->addDaily(new GameNotesProgressMetric()->setName('Daily Tasks')->setCurrentValue(2)->setMaxValue(4));
    }
}

<?php

namespace App\Tests\GamesBundle\Service\Endgame;

use App\GamesBundle\Model\Endgame\EndgameModeReadout;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Service\Endgame\EndgameStatusBuilder;
use PHPUnit\Framework\TestCase;

class EndgameStatusBuilderTest extends TestCase
{
    private function account(): RuntimeAccountDataInterface
    {
        return $this->createStub(RuntimeAccountDataInterface::class);
    }

    private static function endgame(string $name, ?string $fetchedAt = null, string $timezone = 'UTC'): GameEndgame
    {
        return new GameEndgame()
            ->setName($name)
            ->setFetchedAt($fetchedAt !== null ? new \DateTimeImmutable($fetchedAt, new \DateTimeZone($timezone)) : null);
    }

    /**
     * @return list<string>
     */
    private static function modeKeys(array $modes): array
    {
        return array_map(static fn (EndgameModeReadout $mode) => $mode->definition->key, $modes);
    }

    public function testReadsEveryModeOfTheGameInItsOrderWithoutDefinitions(): void
    {
        $first = [self::endgame('First')];
        $third = [self::endgame('Third'), self::endgame('Third (Hard)')];
        $game = new FakeEndgamesGame(['first' => $first, 'third' => $third]);

        $readout = new EndgameStatusBuilder()->read($game, $this->account());

        $this->assertSame(['first', 'second', 'third'], self::modeKeys($readout->modes));
        $this->assertSame(['first', 'second', 'third'], array_column($game->reads, 'mode'));
        $this->assertSame($first, $readout->modes[0]->endgames);
        // A mode read without any result is still a successful read
        $this->assertSame([], $readout->modes[1]->endgames);
        $this->assertTrue($readout->modes[1]->isSuccessful());
        $this->assertSame($third, $readout->modes[2]->endgames);
        $this->assertSame([], $readout->getErrors());
        $this->assertFalse($readout->allFailed());
    }

    public function testReadsOnlyTheGivenDefinitionsInTheirOrder(): void
    {
        $game = new FakeEndgamesGame();

        $readout = new EndgameStatusBuilder()->read($game, $this->account(), [
            FakeEndgamesGame::definition('third'),
            FakeEndgamesGame::definition('first'),
        ]);

        $this->assertSame(['third', 'first'], self::modeKeys($readout->modes));
        $this->assertSame(['third', 'first'], array_column($game->reads, 'mode'));
    }

    public function testNoDefinitionReadsNothing(): void
    {
        $game = new FakeEndgamesGame();

        $readout = new EndgameStatusBuilder()->read($game, $this->account(), []);

        $this->assertSame([], $readout->modes);
        $this->assertSame([], $game->reads);
        $this->assertFalse($readout->allFailed());
        $this->assertNull($readout->getFetchedAt());
    }

    public function testFailingModeKeepsItsErrorAndTheOthersAreRead(): void
    {
        $error = new \RuntimeException('account data');
        $game = new FakeEndgamesGame([
            'first' => [self::endgame('First')],
            'second' => $error,
            'third' => [self::endgame('Third')],
        ]);

        $readout = new EndgameStatusBuilder()->read($game, $this->account());

        $this->assertSame(['first', 'second', 'third'], self::modeKeys($readout->modes));
        $this->assertTrue($readout->modes[0]->isSuccessful());
        $this->assertFalse($readout->modes[1]->isSuccessful());
        $this->assertSame($error, $readout->modes[1]->error);
        $this->assertSame([], $readout->modes[1]->endgames);
        $this->assertTrue($readout->modes[2]->isSuccessful());
        $this->assertSame([$error], $readout->getErrors());
        $this->assertFalse($readout->allFailed());
    }

    public function testEveryModeFailing(): void
    {
        $first = new \RuntimeException('expired cookie');
        // Not only exceptions: an error of the game service stays in its mode too
        $second = new \UnhandledMatchError('unknown mode');
        $third = new \RuntimeException('expired cookie');
        $game = new FakeEndgamesGame(['first' => $first, 'second' => $second, 'third' => $third]);

        $readout = new EndgameStatusBuilder()->read($game, $this->account());

        $this->assertTrue($readout->allFailed());
        $this->assertSame([$first, $second, $third], $readout->getErrors());
        $this->assertNull($readout->getFetchedAt());
    }

    public function testFetchedAtIsTheOldestAnswer(): void
    {
        $game = new FakeEndgamesGame([
            'first' => [self::endgame('First', '2026-11-25 10:05:00'), self::endgame('First (Hard)')],
            // 10:02 in UTC: the oldest instant, whatever the timezone
            'second' => [self::endgame('Second', '2026-11-25 11:02:00', 'Europe/Paris')],
            'third' => new \RuntimeException('not read'),
        ]);

        $readout = new EndgameStatusBuilder()->read($game, $this->account());

        $this->assertSame(
            (new \DateTimeImmutable('2026-11-25 10:02:00', new \DateTimeZone('UTC')))->getTimestamp(),
            $readout->getFetchedAt()?->getTimestamp()
        );
    }

    public function testFetchedAtIsNullWithoutAnyFetchTime(): void
    {
        $game = new FakeEndgamesGame(['first' => [self::endgame('First')]]);

        $this->assertNull(new EndgameStatusBuilder()->read($game, $this->account())->getFetchedAt());
    }

    public function testFreshIsForwardedToEveryMode(): void
    {
        $game = new FakeEndgamesGame();
        $builder = new EndgameStatusBuilder();

        $builder->read($game, $this->account());
        $this->assertSame([false, false, false], array_column($game->reads, 'fresh'));

        $game->reads = [];
        $builder->read($game, $this->account(), [FakeEndgamesGame::definition('second')], fresh: true);
        $this->assertSame([['mode' => 'second', 'fresh' => true]], $game->reads);
    }
}

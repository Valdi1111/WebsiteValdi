<?php

namespace App\Tests\GamesBundle\Service\Endgame;

use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;

/**
 * Game with three endgame modes answering the results given per mode, recording every read.
 */
final class FakeEndgamesGame implements HasEndgamesInterface
{
    /** @var list<array{mode: string, fresh: bool}> */
    public array $reads = [];

    /**
     * @param array<string, list<GameEndgame>|\Throwable> $results mode key => endgames, or the error to throw
     */
    public function __construct(private readonly array $results = [])
    {
    }

    public static function getEndgamesConfigKey(): string
    {
        return 'fake';
    }

    public static function getEndgameDefinitions(): array
    {
        return [
            new EndgameDefinition('first', 'First Mode'),
            new EndgameDefinition('second', 'Second Mode'),
            new EndgameDefinition('third', 'Third Mode'),
        ];
    }

    public static function definition(string $key): EndgameDefinition
    {
        foreach (self::getEndgameDefinitions() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        throw new \LogicException(sprintf('Mode "%s" not declared', $key));
    }

    public function fetchEndgame(EndgameDefinition $definition, RuntimeAccountDataInterface $account, bool $fresh = false): array
    {
        $this->reads[] = ['mode' => $definition->key, 'fresh' => $fresh];

        $result = $this->results[$definition->key] ?? [];
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }
}

<?php

namespace App\GamesBundle\Model\Task;

use App\GamesBundle\Model\Game\GameProfileInterface;

/**
 * A task that the "<provider>:task" command can run by hand, keyed by the task name of the provider schedule
 * (`<provider>.tasks`). The batch message comes from the schedule configuration; this adds what the configuration
 * does not know: a description, and how to run the task for a single game profile.
 */
final readonly class ManualTask
{
    /**
     * @param string $description What the task does, shown in the task list
     * @param (\Closure(GameProfileInterface): list<object>)|null $forProfile Messages that run the task for one profile, null when the task only runs as a batch
     * @param string|null $note Limits worth knowing before running it (supported games, side effects), shown in the task list
     */
    public function __construct(
        public string $description,
        public ?\Closure $forProfile = null,
        public ?string $note = null,
    ) {
    }

    public function supportsProfiles(): bool
    {
        return $this->forProfile !== null;
    }
}

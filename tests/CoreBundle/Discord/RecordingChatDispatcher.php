<?php

namespace App\Tests\CoreBundle\Discord;

use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatDispatcherInterface;
use App\CoreBundle\Model\Chat\ChatDispatchResult;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReplyTarget;

/**
 * ChatDispatcherInterface answering what the test sets, with the calls recorded: the Discord adapter is tested on
 * its own, without users, commands or queue.
 */
final class RecordingChatDispatcher implements ChatDispatcherInterface
{
    /** @var list<array<string, mixed>> */
    public array $commands = [];
    /** @var list<array<string, mixed>> */
    public array $autocompletes = [];
    /** @var list<array<string, mixed>> */
    public array $actions = [];

    public ChatDispatchResult $commandResult;
    public ChatDispatchResult $actionResult;

    /**
     * @param list<ChatChoice> $choices
     */
    public function __construct(
        ?ChatDispatchResult $commandResult = null,
        ?ChatDispatchResult $actionResult = null,
        public array $choices = [],
    ) {
        $this->commandResult = $commandResult ?? ChatDispatchResult::deferred(true);
        $this->actionResult = $actionResult ?? ChatDispatchResult::deferred(true);
    }

    public function dispatchCommand(ChatPlatform $platform, string $platformUserId, string $command, ?string $subcommand, array $arguments, string $locale, ChatReplyTarget $target): ChatDispatchResult
    {
        $this->commands[] = compact('platform', 'platformUserId', 'command', 'subcommand', 'arguments', 'locale', 'target');

        return $this->commandResult;
    }

    public function autocomplete(ChatPlatform $platform, string $platformUserId, string $command, ?string $subcommand, string $option, string $partial, array $arguments): array
    {
        $this->autocompletes[] = compact('platform', 'platformUserId', 'command', 'subcommand', 'option', 'partial', 'arguments');

        return $this->choices;
    }

    public function dispatchAction(ChatPlatform $platform, string $platformUserId, string $actionData, string $locale, ChatReplyTarget $target): ChatDispatchResult
    {
        $this->actions[] = compact('platform', 'platformUserId', 'actionData', 'locale', 'target');

        return $this->actionResult;
    }
}

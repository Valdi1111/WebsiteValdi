<?php

namespace App\Tests\CoreBundle\Chat\Fixtures;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Model\Chat\ChatAutocompleteInterface;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Model\Chat\ChatOptionType;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Model\Chat\ChatUser;

/**
 * A public command (no role, not ephemeral) with one option of each kind; it records what it receives and answers
 * with $reply, or throws $exception.
 */
#[AsChatCommand(name: 'echo', description: 'Repeat what you type', ephemeral: false)]
class EchoChatCommand implements ChatCommandInterface, ChatAutocompleteInterface
{
    /** @var list<ChatRequest> */
    public array $requests = [];
    public ?ChatReply $reply = null;
    public ?\Throwable $exception = null;

    /** @var list<array{?string, string, string, array<string, string|int|bool>}> subcommand, option, partial, arguments */
    public array $autocompleteCalls = [];
    /** @var list<ChatChoice> */
    public array $suggestions = [];
    public ?\Throwable $autocompleteException = null;

    public function configure(ChatCommandBuilder $builder): void
    {
        $builder
            ->addOption('text', 'What to repeat', required: true)
            ->addOption('times', 'How many times', ChatOptionType::Integer)
            ->addOption('loud', 'In capitals', ChatOptionType::Boolean)
            ->addOption('tone', 'How to say it', choices: [new ChatChoice('Calm', 'calm'), new ChatChoice('Angry', 'angry')])
            ->addOption('friend', 'Who to say it to', autocomplete: true);
    }

    public function execute(ChatRequest $request): ChatReply
    {
        $this->requests[] = $request;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->reply ?? ChatReply::text((string) $request->get('text'));
    }

    public function autocomplete(ChatUser $user, ?string $subcommand, string $option, string $partial, array $arguments): array
    {
        $this->autocompleteCalls[] = [$subcommand, $option, $partial, $arguments];
        if ($this->autocompleteException !== null) {
            throw $this->autocompleteException;
        }

        return $this->suggestions;
    }
}

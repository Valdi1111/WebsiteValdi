<?php

namespace App\CoreBundle\Service\Chat\Discord;

use App\CoreBundle\Exception\InvalidDiscordInteractionException;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatDispatcherInterface;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Model\Discord\Interaction\DiscordInteraction;
use App\CoreBundle\Model\Discord\Interaction\DiscordInteractionOption;
use Psr\Clock\ClockInterface;

/**
 * Synchronous answer to a verified Discord interaction (https://discord.com/developers/docs/interactions/receiving-and-responding#responding-to-an-interaction),
 * within the 3 seconds Discord waits: the request goes to ChatDispatcherInterface, and its result becomes the
 * interaction response.
 *
 * - PING: PONG, how Discord checks the endpoint;
 * - command: deferred (type 5, "thinking…", ephemeral when the command is), the worker then edits the answer in;
 *   or an immediate answer for the user alone (not linked, unknown command, missing role...);
 * - button: deferred update (type 6, the message stays as it is until the worker edits it), or a refusal as a new
 *   message for the user alone, the original left untouched;
 * - autocomplete: the suggestions at once (type 8).
 */
class DiscordInteractionHandler
{
    public const int RESPONSE_PONG = 1;
    public const int RESPONSE_CHANNEL_MESSAGE = 4;
    public const int RESPONSE_DEFERRED_CHANNEL_MESSAGE = 5;
    public const int RESPONSE_DEFERRED_UPDATE_MESSAGE = 6;
    public const int RESPONSE_AUTOCOMPLETE_RESULT = 8;
    public const int FLAG_EPHEMERAL = 64;

    public const int MAX_CHOICES = 25;
    public const int MAX_CHOICE_LENGTH = 100;

    public function __construct(
        private readonly ChatDispatcherInterface $dispatcher,
        private readonly DiscordReplyRenderer $renderer,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed> the interaction response
     *
     * @throws InvalidDiscordInteractionException when the type is not supported or a field it needs is missing
     */
    public function handle(DiscordInteraction $interaction): array
    {
        return match ($interaction->type) {
            DiscordInteraction::TYPE_PING => ['type' => self::RESPONSE_PONG],
            DiscordInteraction::TYPE_APPLICATION_COMMAND => $this->handleCommand($interaction),
            DiscordInteraction::TYPE_MESSAGE_COMPONENT => $this->handleComponent($interaction),
            DiscordInteraction::TYPE_APPLICATION_COMMAND_AUTOCOMPLETE => $this->handleAutocomplete($interaction),
            default => throw new InvalidDiscordInteractionException(sprintf('Unsupported Discord interaction type %d.', $interaction->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function handleCommand(DiscordInteraction $interaction): array
    {
        $userId = self::requireUserId($interaction);
        $command = self::requireCommandName($interaction);
        $target = $this->createTarget($interaction);

        $invocation = self::flatten($interaction->data->options ?? []);
        if ($invocation === null) {
            // Sub-command groups are never registered (ChatCommandBuilder has one level)
            return $this->message(ChatReply::error(), true);
        }
        [$subcommand, $arguments] = $invocation;

        $result = $this->dispatcher->dispatchCommand(ChatPlatform::Discord, $userId, $command, $subcommand, $arguments, $interaction->getLanguage(), $target);
        if ($result->isDeferred()) {
            // Only the deferred response decides whether the answer is ephemeral: the later edit cannot change it
            return $result->ephemeral
                ? ['type' => self::RESPONSE_DEFERRED_CHANNEL_MESSAGE, 'data' => ['flags' => self::FLAG_EPHEMERAL]]
                : ['type' => self::RESPONSE_DEFERRED_CHANNEL_MESSAGE];
        }

        return $this->message($result->reply, $result->ephemeral);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleComponent(DiscordInteraction $interaction): array
    {
        $userId = self::requireUserId($interaction);
        $customId = $interaction->data?->customId;
        if ($customId === null || $customId === '') {
            throw new InvalidDiscordInteractionException('Discord component interaction without a custom_id.');
        }
        $target = $this->createTarget($interaction);

        $result = $this->dispatcher->dispatchAction(ChatPlatform::Discord, $userId, $customId, $interaction->getLanguage(), $target);
        if ($result->isDeferred()) {
            return ['type' => self::RESPONSE_DEFERRED_UPDATE_MESSAGE];
        }

        // A refusal: a new message for the presser alone, whoever's the message of the button is
        return $this->message($result->reply, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleAutocomplete(DiscordInteraction $interaction): array
    {
        $userId = self::requireUserId($interaction);
        $command = self::requireCommandName($interaction);

        $invocation = self::flatten($interaction->data->options ?? []);
        $focused = $invocation[2] ?? null;
        if ($focused === null) {
            return self::choices([]);
        }
        [$subcommand, $arguments] = $invocation;

        // The partial input as typed, a string even for a number option
        $partial = is_bool($focused->value) ? ($focused->value ? 'true' : 'false') : (string) $focused->value;

        return self::choices($this->dispatcher->autocomplete(ChatPlatform::Discord, $userId, $command, $subcommand, $focused->name, $partial, $arguments));
    }

    /**
     * @return array{type: int, data: array<string, mixed>}
     */
    private function message(?ChatReply $reply, bool $ephemeral): array
    {
        $data = $this->renderer->render($reply ?? ChatReply::error());
        if ($ephemeral) {
            $data['flags'] = self::FLAG_EPHEMERAL;
        }

        return ['type' => self::RESPONSE_CHANNEL_MESSAGE, 'data' => $data];
    }

    /**
     * @param list<ChatChoice> $choices
     * @return array{type: int, data: array{choices: list<array{name: string, value: string|int}>}}
     */
    private static function choices(array $choices): array
    {
        $rendered = [];
        foreach ($choices as $choice) {
            $name = DiscordEmbedLimiter::truncate($choice->name, self::MAX_CHOICE_LENGTH);
            // Discord refuses the whole list for one invalid choice; a cut value would be another value
            if (trim($name) === '' || (is_string($choice->value) && mb_strlen($choice->value) > self::MAX_CHOICE_LENGTH)) {
                continue;
            }
            $rendered[] = ['name' => $name, 'value' => $choice->value];
            if (count($rendered) === self::MAX_CHOICES) {
                break;
            }
        }

        return ['type' => self::RESPONSE_AUTOCOMPLETE_RESULT, 'data' => ['choices' => $rendered]];
    }

    /**
     * The options as the dispatcher takes them: the sub-command, if any, gives its name and holds the options.
     *
     * @param list<DiscordInteractionOption> $options
     * @return array{0: string|null, 1: array<string, string|int|bool>, 2: DiscordInteractionOption|null}|null
     *         subcommand, arguments by name (the focused option apart), focused option; null for a sub-command group
     */
    private static function flatten(array $options): ?array
    {
        $subcommand = null;
        foreach ($options as $option) {
            if ($option->type === DiscordInteractionOption::TYPE_SUB_COMMAND_GROUP) {
                return null;
            }
            if ($option->type === DiscordInteractionOption::TYPE_SUB_COMMAND) {
                $subcommand = $option->name;
                $options = $option->options;
                break;
            }
        }

        $arguments = [];
        $focused = null;
        foreach ($options as $option) {
            if ($option->focused) {
                $focused = $option;
            } elseif ($option->value !== null) {
                $arguments[$option->name] = $option->value;
            }
        }

        return [$subcommand, $arguments, $focused];
    }

    private function createTarget(DiscordInteraction $interaction): ChatReplyTarget
    {
        if ($interaction->applicationId === null || !ctype_digit($interaction->applicationId) || $interaction->token === null || $interaction->token === '') {
            throw new InvalidDiscordInteractionException('Discord interaction without application id or token.');
        }

        return new ChatReplyTarget(
            ChatPlatform::Discord,
            ['application_id' => $interaction->applicationId, 'token' => $interaction->token],
            $this->clock->now(),
        );
    }

    private static function requireUserId(DiscordInteraction $interaction): string
    {
        return $interaction->getUserId() ?? throw new InvalidDiscordInteractionException('Discord interaction without a user.');
    }

    private static function requireCommandName(DiscordInteraction $interaction): string
    {
        $name = $interaction->data?->name;
        if ($name === null || $name === '') {
            throw new InvalidDiscordInteractionException('Discord command interaction without a command name.');
        }

        return $name;
    }
}

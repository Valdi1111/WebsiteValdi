<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Exception\InvalidChatActionRefException;
use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\Message\Chat\HandleChatActionMessage;
use App\CoreBundle\Model\Chat\ChatAutocompleteInterface;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandDefinition;
use App\CoreBundle\Model\Chat\ChatDispatcherInterface;
use App\CoreBundle\Model\Chat\ChatDispatchResult;
use App\CoreBundle\Model\Chat\ChatExecutionResult;
use App\CoreBundle\Model\Chat\ChatOption;
use App\CoreBundle\Model\Chat\ChatOptionType;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Model\Chat\ChatUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Synchronous phase of every chat request (see ChatDispatcherInterface), plus runCommand() for chat:run: the same
 * checks (prepareCommand()), then the command run in this process instead of queued.
 *
 * Only database lookups happen here (Discord waits 3 s at most). Every refusal is an answer for the user alone; an
 * unexpected failure becomes the generic error, logged by class and code only.
 */
#[AsAlias(ChatDispatcherInterface::class)]
class ChatDispatcher implements ChatDispatcherInterface
{
    public const string FORBIDDEN = 'You cannot use this command.';
    public const string INVALID_ACTION = 'This button is no longer valid.';
    public const string PROFILE_ROUTE = 'core_profile';
    /** Discord shows at most 25 suggestions */
    public const int MAX_CHOICES = 25;

    public function __construct(
        private readonly ChatUserResolver $userResolver,
        private readonly ChatCommandRegistry $commands,
        private readonly ChatActionRegistry $actions,
        private readonly ChatActionCodec $codec,
        private readonly ChatExecutor $executor,
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urlGenerator,
        // The profile link in "not linked": CoreBundle routes have no host, and the request (if any) comes from Discord
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $defaultUri,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatchCommand(
        ChatPlatform $platform,
        string $platformUserId,
        string $command,
        ?string $subcommand,
        array $arguments,
        string $locale,
        ChatReplyTarget $target,
    ): ChatDispatchResult {
        try {
            $message = $this->prepareCommand($platform, $platformUserId, $command, $subcommand, $arguments, $locale, $target);
            if ($message instanceof ChatReply) {
                return ChatDispatchResult::immediate($message);
            }

            $ephemeral = $this->commands->getDefinition($command)->ephemeral;
            $this->bus->dispatch($message);

            return ChatDispatchResult::deferred($ephemeral);
        } catch (\Throwable $e) {
            return ChatDispatchResult::immediate($this->failure('Chat command not dispatched: {error}', $e, ['platform' => $platform->value, 'command' => $command]));
        }
    }

    /**
     * The synchronous path, for chat:run: the checks of dispatchCommand(), then the command run at once by the
     * executor, as a worker would. A refusal comes back as a failed result.
     *
     * @param array<string, string|int|bool> $arguments by option name
     */
    public function runCommand(
        ChatPlatform $platform,
        string $platformUserId,
        string $command,
        ?string $subcommand,
        array $arguments,
        string $locale = 'en',
    ): ChatExecutionResult {
        try {
            $message = $this->prepareCommand($platform, $platformUserId, $command, $subcommand, $arguments, $locale, new ChatReplyTarget($platform));
        } catch (\Throwable $e) {
            return ChatExecutionResult::failed($this->failure('Chat command not run: {error}', $e, ['platform' => $platform->value, 'command' => $command]));
        }

        return $message instanceof ChatReply ? ChatExecutionResult::failed($message) : $this->executor->executeCommand($message);
    }

    public function autocomplete(
        ChatPlatform $platform,
        string $platformUserId,
        string $command,
        ?string $subcommand,
        string $option,
        string $partial,
        array $arguments,
    ): array {
        try {
            $user = $this->userResolver->resolve($platform, $platformUserId);
            // The role is checked too: the suggestions come from the user's data that the command shows
            if ($user === null || $user->user->isDisabled() || !$this->commands->isGranted($user->user, $command)) {
                return [];
            }
            $options = self::findOptions($this->commands->getDefinition($command), $subcommand);
            $declared = array_find($options ?? [], static fn (ChatOption $candidate): bool => $candidate->name === $option);
            $service = $this->commands->get($command);
            if ($declared === null || !$declared->autocomplete || !$service instanceof ChatAutocompleteInterface) {
                return [];
            }

            // The other options only as far as they are valid: they are still being typed
            $filled = [];
            foreach ($options as $other) {
                $value = $other->name !== $option && isset($arguments[$other->name]) ? self::coerce($other, $arguments[$other->name]) : null;
                if ($value !== null) {
                    $filled[$other->name] = $value;
                }
            }

            $choices = $service->autocomplete($user, $subcommand, $option, $partial, $filled);

            return array_slice(array_values(array_filter($choices, static fn (mixed $choice): bool => $choice instanceof ChatChoice)), 0, self::MAX_CHOICES);
        } catch (\Throwable $e) {
            $this->logger->warning('Chat autocomplete failed: {error}', ['platform' => $platform->value, 'command' => $command, 'option' => $option, 'error' => $e::class, 'code' => $e->getCode()]);

            return [];
        }
    }

    public function dispatchAction(
        ChatPlatform $platform,
        string $platformUserId,
        string $actionData,
        string $locale,
        ChatReplyTarget $target,
    ): ChatDispatchResult {
        $prepared = $this->prepareAction($platform, $platformUserId, $actionData, $locale, $target);
        if ($prepared instanceof ChatReply) {
            return ChatDispatchResult::immediate($prepared);
        }

        try {
            $this->bus->dispatch($prepared);
        } catch (\Throwable $e) {
            return ChatDispatchResult::immediate($this->failure('Chat action not dispatched: {error}', $e, ['platform' => $platform->value]));
        }

        // The answer edits the message of the button, which keeps its visibility
        return ChatDispatchResult::deferred(true);
    }

    /**
     * The checks of a button press (user, button data, handler, authorize()), without queueing it: dispatchAction()
     * queues the result, and a platform that runs the press itself (Telegram, from its worker) answers the button first,
     * then gives the result to ChatExecutor::handleAction(). A refusal is the answer for the user alone.
     *
     * @return HandleChatActionMessage|ChatReply the authorized press, or the refusal
     */
    public function prepareAction(
        ChatPlatform $platform,
        string $platformUserId,
        string $actionData,
        string $locale,
        ChatReplyTarget $target,
    ): HandleChatActionMessage|ChatReply {
        try {
            $user = $this->resolveUser($platform, $platformUserId);
            if ($user instanceof ChatReply) {
                return $user;
            }

            // The data comes from the platform: anything that is not a known button is refused the same way
            try {
                $ref = $this->codec->decode($actionData);
            } catch (InvalidChatActionRefException) {
                return ChatReply::error(self::INVALID_ACTION);
            }
            if (!$this->actions->has($ref->handler)) {
                return ChatReply::error(self::INVALID_ACTION);
            }

            $refusal = $this->actions->get($ref->handler)->authorize($user, $ref);
            if ($refusal !== null) {
                return ChatReply::error($refusal);
            }

            return new HandleChatActionMessage((int) $user->user->getId(), $platform, $platformUserId, $ref, $locale, $target);
        } catch (\Throwable $e) {
            return $this->failure('Chat action not authorized: {error}', $e, ['platform' => $platform->value]);
        }
    }

    /**
     * The checks shared by both paths: user, command, role, subcommand and arguments.
     *
     * @param array<string, string|int|bool> $arguments
     * @return ExecuteChatCommandMessage|ChatReply the accepted command, or the refusal to answer at once
     */
    private function prepareCommand(
        ChatPlatform $platform,
        string $platformUserId,
        string $command,
        ?string $subcommand,
        array $arguments,
        string $locale,
        ChatReplyTarget $target,
    ): ExecuteChatCommandMessage|ChatReply {
        $user = $this->resolveUser($platform, $platformUserId);
        if ($user instanceof ChatReply) {
            return $user;
        }

        if (!$this->commands->has($command)) {
            return ChatReply::error(sprintf('Unknown command%s. Use /help to see the commands you can use.', self::quote('/', $command)));
        }
        if (!$this->commands->isGranted($user->user, $command)) {
            return ChatReply::error(self::FORBIDDEN);
        }

        $definition = $this->commands->getDefinition($command);
        $options = self::findOptions($definition, $subcommand);
        if ($options === null) {
            return ChatReply::error(self::subcommandError($definition, $subcommand));
        }
        $bound = self::bindArguments($options, $arguments);
        if ($bound instanceof ChatReply) {
            return $bound;
        }

        return new ExecuteChatCommandMessage((int) $user->user->getId(), $platform, $platformUserId, $command, $subcommand, $bound, $locale, $target);
    }

    /**
     * @return ChatUser|ChatReply the linked, enabled user, or the refusal
     */
    private function resolveUser(ChatPlatform $platform, string $platformUserId): ChatUser|ChatReply
    {
        $user = $this->userResolver->resolve($platform, $platformUserId);
        if ($user === null) {
            return ChatReply::text(sprintf(
                'Your %s account is not linked to a site account yet. Link it from your profile: %s',
                match ($platform) {
                    ChatPlatform::Discord => 'Discord',
                    ChatPlatform::Telegram => 'Telegram',
                    ChatPlatform::Cli => 'chat',
                },
                rtrim($this->defaultUri, '/') . $this->urlGenerator->generate(self::PROFILE_ROUTE),
            ));
        }
        if ($user->user->isDisabled()) {
            return ChatReply::error(ChatUserResolver::DISABLED);
        }

        return $user;
    }

    /**
     * @return list<ChatOption>|null the options of the command or of its subcommand; null when the subcommand is
     *                               missing, unknown, or given to a command without subcommands
     */
    private static function findOptions(ChatCommandDefinition $definition, ?string $subcommand): ?array
    {
        if ($definition->subcommands === []) {
            return $subcommand === null ? $definition->options : null;
        }

        return $subcommand !== null ? $definition->getSubcommand($subcommand)?->options : null;
    }

    private static function subcommandError(ChatCommandDefinition $definition, ?string $subcommand): string
    {
        if ($definition->subcommands === []) {
            return sprintf('The command /%s has no subcommands.', $definition->name);
        }

        $names = implode(', ', array_map(static fn ($candidate): string => $candidate->name, $definition->subcommands));

        return $subcommand === null
            ? sprintf('Choose what /%s should do: %s.', $definition->name, $names)
            : sprintf('Unknown subcommand%s of /%s. Subcommands: %s.', self::quote('', $subcommand), $definition->name, $names);
    }

    /**
     * Only the declared options, in their order, as their type: Discord sends typed values, the typed command lines
     * (chat:run, Telegram) strings. An empty string counts as not given.
     *
     * @param list<ChatOption>               $options
     * @param array<string, string|int|bool> $arguments
     * @return array<string, string|int|bool>|ChatReply
     */
    private static function bindArguments(array $options, array $arguments): array|ChatReply
    {
        $bound = [];
        foreach ($options as $option) {
            $value = $arguments[$option->name] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                if ($option->required) {
                    return ChatReply::error(sprintf('The option "%s" is required.', $option->name));
                }
                continue;
            }

            $coerced = self::coerce($option, $value);
            if ($coerced === null) {
                return ChatReply::error(self::invalidValue($option));
            }
            $bound[$option->name] = $coerced;
        }

        return $bound;
    }

    /**
     * @return string|int|bool|null null when the value does not fit the type or the choices of the option
     */
    private static function coerce(ChatOption $option, mixed $value): string|int|bool|null
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = match ($option->type) {
            ChatOptionType::String => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            ChatOptionType::Integer => match (true) {
                is_int($value) => $value,
                // At most 18 digits: always within an int
                is_string($value) && preg_match('/^[+-]?\d{1,18}$/', trim($value)) === 1 => (int) trim($value),
                default => null,
            },
            ChatOptionType::Boolean => is_bool($value) ? $value : match (strtolower(trim((string) $value))) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off' => false,
                default => null,
            },
        };

        // Compared as strings: a choice value is a string or an int, whatever the type of the option
        $allowed = array_map(static fn (ChatChoice $choice): string => (string) $choice->value, $option->choices);
        if ($value !== null && $allowed !== [] && !in_array(is_bool($value) ? (string) (int) $value : (string) $value, $allowed, true)) {
            return null;
        }

        return $value;
    }

    private static function invalidValue(ChatOption $option): string
    {
        if ($option->choices !== []) {
            return sprintf('The option "%s" must be one of: %s.', $option->name, implode(', ', array_map(static fn (ChatChoice $choice): string => (string) $choice->value, $option->choices)));
        }

        return match ($option->type) {
            ChatOptionType::Integer => sprintf('The option "%s" must be a whole number.', $option->name),
            ChatOptionType::Boolean => sprintf('The option "%s" must be true or false.', $option->name),
            ChatOptionType::String => sprintf('The option "%s" must be text.', $option->name),
        };
    }

    /**
     * A name the user typed, repeated in the answer only when it is a valid name: nothing else of the user's text
     * reaches a message of the bot.
     */
    private static function quote(string $prefix, string $name): string
    {
        return preg_match(ChatCommandBuilder::NAME_PATTERN, $name) === 1 ? sprintf(' "%s%s"', $prefix, $name) : '';
    }

    /**
     * @param array<string, string> $context
     */
    private function failure(string $message, \Throwable $e, array $context): ChatReply
    {
        $this->logger->error($message, $context + ['error' => $e::class, 'code' => $e->getCode()]);

        return ChatReply::error();
    }
}

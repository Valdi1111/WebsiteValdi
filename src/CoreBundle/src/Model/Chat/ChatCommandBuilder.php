<?php

namespace App\CoreBundle\Model\Chat;

use App\CoreBundle\Exception\InvalidChatCommandException;

/**
 * Options and subcommands of a chat command (ChatCommandInterface::configure()), checked against the strictest rule of
 * the platforms when the definition is built:
 * - names: 1 to 32 lowercase letters, digits or "_" (Telegram commands; Discord allows more);
 * - descriptions: 1 to 100 characters (Discord), 3 for a command (Telegram);
 * - at most 25 options or subcommands, required options before the optional ones, at most 25 choices, choices and
 *   autocomplete not together, options or subcommands but not both, names unique (Discord).
 */
final class ChatCommandBuilder
{
    public const string NAME_PATTERN = '/^[a-z0-9_]{1,32}$/';
    public const int MAX_DESCRIPTION = 100;
    public const int MIN_COMMAND_DESCRIPTION = 3;
    public const int MAX_ITEMS = 25;

    /** @var list<ChatOption> */
    private array $options = [];

    /** @var list<ChatSubcommandDefinition> */
    private array $subcommands = [];

    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly ?string $role = null,
        private readonly bool $ephemeral = true,
        private readonly bool $isSubcommand = false,
    ) {
    }

    /**
     * @param list<ChatChoice> $choices
     */
    public function addOption(
        string $name,
        string $description,
        ChatOptionType $type = ChatOptionType::String,
        bool $required = false,
        bool $autocomplete = false,
        array $choices = [],
    ): self {
        $this->options[] = new ChatOption($name, $description, $type, $required, $autocomplete, $choices);

        return $this;
    }

    /**
     * @param (\Closure(ChatCommandBuilder): void)|null $configure adds the options of the subcommand
     */
    public function addSubcommand(string $name, string $description, ?\Closure $configure = null): self
    {
        if ($this->isSubcommand) {
            throw new InvalidChatCommandException(sprintf('Subcommand "%s" of "%s": subcommands cannot be nested.', $name, $this->name));
        }

        $builder = new self($name, $description, isSubcommand: true);
        if ($configure !== null) {
            $configure($builder);
        }
        $builder->validateOptions();
        $this->subcommands[] = new ChatSubcommandDefinition($name, $description, $builder->options);

        return $this;
    }

    /**
     * @throws InvalidChatCommandException
     */
    public function getDefinition(): ChatCommandDefinition
    {
        self::validateName($this->name, 'Command');
        self::validateDescription($this->description, sprintf('Command "%s"', $this->name), self::MIN_COMMAND_DESCRIPTION);
        if ($this->options !== [] && $this->subcommands !== []) {
            throw new InvalidChatCommandException(sprintf('Command "%s" has both options and subcommands.', $this->name));
        }
        $this->validateOptions();
        if (count($this->subcommands) > self::MAX_ITEMS) {
            throw new InvalidChatCommandException(sprintf('Command "%s" has more than %d subcommands.', $this->name, self::MAX_ITEMS));
        }
        self::validateUnique(array_map(static fn (ChatSubcommandDefinition $subcommand): string => $subcommand->name, $this->subcommands), sprintf('Command "%s"', $this->name));

        return new ChatCommandDefinition($this->name, $this->description, $this->role, $this->ephemeral, $this->options, $this->subcommands);
    }

    private function validateOptions(): void
    {
        $owner = sprintf('%s "%s"', $this->isSubcommand ? 'Subcommand' : 'Command', $this->name);
        if ($this->isSubcommand) {
            self::validateName($this->name, 'Subcommand');
            self::validateDescription($this->description, $owner);
        }
        if (count($this->options) > self::MAX_ITEMS) {
            throw new InvalidChatCommandException(sprintf('%s has more than %d options.', $owner, self::MAX_ITEMS));
        }

        $optionalSeen = false;
        foreach ($this->options as $option) {
            $label = sprintf('Option "%s" of %s', $option->name, $owner);
            self::validateName($option->name, $label);
            self::validateDescription($option->description, $label);
            if ($option->required && $optionalSeen) {
                throw new InvalidChatCommandException(sprintf('%s is required after an optional one.', $label));
            }
            $optionalSeen = $optionalSeen || !$option->required;
            if ($option->choices !== [] && $option->autocomplete) {
                throw new InvalidChatCommandException(sprintf('%s has both choices and autocomplete.', $label));
            }
            if (count($option->choices) > self::MAX_ITEMS) {
                throw new InvalidChatCommandException(sprintf('%s has more than %d choices.', $label, self::MAX_ITEMS));
            }
            foreach ($option->choices as $choice) {
                self::validateDescription($choice->name, sprintf('A choice of %s', $label));
            }
        }
        self::validateUnique(array_map(static fn (ChatOption $option): string => $option->name, $this->options), $owner);
    }

    private static function validateName(string $name, string $label): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidChatCommandException(sprintf('%s "%s": a name has 1 to 32 lowercase letters, digits or "_".', $label, $name));
        }
    }

    private static function validateDescription(string $description, string $label, int $min = 1): void
    {
        $length = mb_strlen($description);
        if ($length < $min || $length > self::MAX_DESCRIPTION) {
            throw new InvalidChatCommandException(sprintf('%s: a description has %d to %d characters.', $label, $min, self::MAX_DESCRIPTION));
        }
    }

    /**
     * @param list<string> $names
     */
    private static function validateUnique(array $names, string $owner): void
    {
        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1));
        if ($duplicates !== []) {
            throw new InvalidChatCommandException(sprintf('%s repeats "%s".', $owner, implode('", "', $duplicates)));
        }
    }
}

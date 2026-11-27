<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Exception\InvalidChatCommandLineException;
use App\CoreBundle\Model\Chat\ChatCommandLine;
use App\CoreBundle\Model\Chat\ChatOption;

/**
 * Reads a command typed as text (chat:run, Telegram messages): "/remind stamina 120", "help", "/help@MyBot",
 * "/note \"two words\"", "/remind stamina value=120".
 *
 * After the name come the subcommand, when the command has subcommands, then the arguments: by position in the order
 * the options are declared, or as name=value for a declared option. Names are read without case. Double quotes (also
 * the typographic ones that phone keyboards type) group words, and a quoted "a=b" is a value, not a name.
 *
 * An unknown command or subcommand is not an error here: it is returned as typed, so that the dispatcher refuses it
 * with the same answer as on every platform.
 */
class ChatCommandLineParser
{
    private const array QUOTES = ['"', '“', '”'];

    public function __construct(
        private readonly ChatCommandRegistry $commands,
    ) {
    }

    /**
     * @throws InvalidChatCommandLineException when the line has no command, or more arguments than the options
     */
    public function parse(string $line): ChatCommandLine
    {
        $tokens = self::tokenize($line);
        $head = array_shift($tokens);
        $name = ltrim($head[0] ?? '', '/');
        $botName = null;
        // Telegram adds "@BotName" to a command chosen from the menu in a group
        if (($at = strpos($name, '@')) !== false) {
            $botName = substr($name, $at + 1);
            $name = substr($name, 0, $at);
        }
        $name = mb_strtolower($name);
        if ($name === '') {
            throw new InvalidChatCommandLineException('Type a command, e.g. /help.');
        }
        if (!$this->commands->has($name)) {
            return new ChatCommandLine($name, botName: $botName);
        }

        $definition = $this->commands->getDefinition($name);
        $subcommand = null;
        $options = $definition->options;
        if ($definition->subcommands !== [] && $tokens !== []) {
            $subcommand = mb_strtolower(array_shift($tokens)[0]);
            $options = $definition->getSubcommand($subcommand)?->options;
            if ($options === null) {
                return new ChatCommandLine($name, $subcommand, botName: $botName);
            }
        }

        $optionNames = array_map(static fn (ChatOption $option): string => $option->name, $options);
        $named = [];
        $positional = [];
        foreach ($tokens as [$token, $quoted]) {
            $pair = $quoted ? null : explode('=', $token, 2);
            if ($pair !== null && count($pair) === 2 && in_array(mb_strtolower($pair[0]), $optionNames, true)) {
                $named[mb_strtolower($pair[0])] = $pair[1];
            } else {
                $positional[] = $token;
            }
        }

        $arguments = [];
        foreach ($optionNames as $optionName) {
            if (array_key_exists($optionName, $named)) {
                $arguments[$optionName] = $named[$optionName];
            } elseif ($positional !== []) {
                $arguments[$optionName] = array_shift($positional);
            }
        }
        if ($positional !== []) {
            throw new InvalidChatCommandLineException(sprintf(
                'Too many arguments for /%s%s: %s.',
                $name,
                $subcommand !== null ? ' ' . $subcommand : '',
                $optionNames === [] ? 'it takes none' : 'it takes ' . implode(', ', $optionNames),
            ));
        }

        return new ChatCommandLine($name, $subcommand, $arguments, $botName);
    }

    /**
     * Words separated by spaces, or grouped in quotes; an unclosed quote runs to the end of the line.
     *
     * @return list<array{string, bool}> each word, and whether it starts with a quote
     */
    private static function tokenize(string $line): array
    {
        $tokens = [];
        $current = null;
        $quotedFromStart = false;
        $inQuotes = false;
        foreach (mb_str_split(trim($line)) as $char) {
            if (in_array($char, self::QUOTES, true)) {
                if ($current === null) {
                    $current = '';
                    $quotedFromStart = true;
                }
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && preg_match('/^\s$/u', $char) === 1) {
                if ($current !== null) {
                    $tokens[] = [$current, $quotedFromStart];
                    $current = null;
                    $quotedFromStart = false;
                }
            } else {
                $current = ($current ?? '') . $char;
            }
        }
        if ($current !== null) {
            $tokens[] = [$current, $quotedFromStart];
        }

        return $tokens;
    }
}

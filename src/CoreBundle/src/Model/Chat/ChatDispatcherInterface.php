<?php

namespace App\CoreBundle\Model\Chat;

/**
 * Synchronous phase of a platform request, the same for every platform: who the user is, whether the command exists
 * and the user may run it, then the work queued on core_chat. Platforms call it from their entry point and only
 * translate the result (ChatDispatchResult) into their own acknowledgement.
 */
interface ChatDispatcherInterface
{
    /**
     * @param array<string, string|int|bool> $arguments by option name
     */
    public function dispatchCommand(
        ChatPlatform $platform,
        string $platformUserId,
        string $command,
        ?string $subcommand,
        array $arguments,
        string $locale,
        ChatReplyTarget $target,
    ): ChatDispatchResult;

    /**
     * Suggestions for an option, synchronously; an empty list when the user or the command cannot be resolved.
     *
     * @param array<string, string|int|bool> $arguments the other options already filled in
     * @return list<ChatChoice>
     */
    public function autocomplete(
        ChatPlatform $platform,
        string $platformUserId,
        string $command,
        ?string $subcommand,
        string $option,
        string $partial,
        array $arguments,
    ): array;

    /**
     * @param string $actionData the encoded ChatActionRef of the button (Discord custom_id, Telegram callback_data)
     */
    public function dispatchAction(
        ChatPlatform $platform,
        string $platformUserId,
        string $actionData,
        string $locale,
        ChatReplyTarget $target,
    ): ChatDispatchResult;
}

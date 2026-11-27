<?php

namespace App\CoreBundle\Model\Chat;

/**
 * Suggestions for the options declared with autocomplete. Runs in the synchronous phase (Discord answers within 3 s):
 * database only, no API call, at most 25 choices.
 */
interface ChatAutocompleteInterface
{
    /**
     * @param array<string, string|int|bool> $arguments the other options already filled in
     * @return list<ChatChoice>
     */
    public function autocomplete(ChatUser $user, ?string $subcommand, string $option, string $partial, array $arguments): array;
}

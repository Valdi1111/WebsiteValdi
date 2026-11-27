<?php

namespace App\CoreBundle\Model\Chat;

/**
 * Handler of the buttons of one kind, with #[AsChatAction] on the class.
 */
interface ChatActionHandlerInterface
{
    /**
     * Synchronous check before the press is queued (database only, within 3 s): whether the user may press it, e.g. the
     * profile of the arguments is theirs.
     *
     * @return string|null null when allowed, else the refusal shown only to the user
     */
    public function authorize(ChatUser $user, ChatActionRef $ref): ?string;

    /**
     * Runs in the worker; the answer replaces the message of the button.
     */
    public function handle(ChatUser $user, ChatActionRef $ref, string $locale): ChatReply;
}

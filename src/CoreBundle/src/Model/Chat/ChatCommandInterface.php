<?php

namespace App\CoreBundle\Model\Chat;

/**
 * A chat command, with #[AsChatCommand] on the class. execute() runs in the worker (or chat:run): it may call APIs, and
 * any exception becomes a generic error answer.
 */
interface ChatCommandInterface
{
    /**
     * Options and subcommands, as Console's configure().
     */
    public function configure(ChatCommandBuilder $builder): void;

    public function execute(ChatRequest $request): ChatReply;
}

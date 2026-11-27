<?php

namespace App\CoreBundle\Model\Chat;

use App\CoreBundle\Exception\ChatReplyNotSentException;

/**
 * Sends the answers of one platform for the worker. Autoconfigured with the tag core.chat_reply_sender
 * (CoreBundle::loadExtension()).
 */
interface ChatReplySenderInterface
{
    public const string TAG = 'core.chat_reply_sender';

    public function supports(ChatPlatform $platform): bool;

    /**
     * @throws ChatReplyNotSentException when the platform refused or could not be reached; its message never holds
     *                                   the target's credentials (URL with the interaction token...)
     */
    public function send(ChatReplyTarget $target, ChatReply $reply, ChatReplyMode $mode): void;
}

<?php

namespace App\Tests\CoreBundle\Chat\Fixtures;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;

/**
 * A command for ROLE_ADMIN only, without options.
 */
#[AsChatCommand(name: 'admin', description: 'Do admin things', role: 'ROLE_ADMIN')]
class AdminChatCommand implements ChatCommandInterface
{
    public function configure(ChatCommandBuilder $builder): void
    {
    }

    public function execute(ChatRequest $request): ChatReply
    {
        return ChatReply::text('done');
    }
}

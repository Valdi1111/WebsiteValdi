<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\Message\Chat\HandleChatActionMessage;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * The chat messages go to core_chat only (#[AsMessage] on the classes), never to the queues of the reminders.
 */
class ChatMessagesRoutingTest extends KernelTestCase
{
    public function testChatMessagesAreRoutedToCoreChat(): void
    {
        /** @var SendersLocatorInterface $senders */
        $senders = static::getContainer()->get('messenger.senders_locator');
        $target = new ChatReplyTarget(ChatPlatform::Discord);

        foreach ([
            new ExecuteChatCommandMessage(1, ChatPlatform::Discord, '1', 'help', null, [], 'en', $target),
            new HandleChatActionMessage(1, ChatPlatform::Discord, '1', new ChatActionRef('refresh'), 'en', $target),
        ] as $message) {
            $this->assertSame(['core_chat'], array_keys(iterator_to_array($senders->getSenders(new Envelope($message)))), $message::class);
        }
    }
}

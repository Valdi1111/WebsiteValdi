<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\Message\Chat\HandleChatActionMessage;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Service\Chat\ChatExecutor;
use App\CoreBundle\Service\Chat\ChatUserResolver;
use PHPUnit\Framework\TestCase;

class ChatExecutorTest extends TestCase
{
    private ChatTestKit $kit;

    protected function setUp(): void
    {
        $this->kit = new ChatTestKit();
        $this->kit->user(1);
    }

    public static function commandMessage(int $userId = 1, string $platformUserId = 'd1', string $command = 'echo', ChatReplyTarget $target = new ChatReplyTarget(ChatPlatform::Discord)): ExecuteChatCommandMessage
    {
        return new ExecuteChatCommandMessage($userId, ChatPlatform::Discord, $platformUserId, $command, null, ['text' => 'hi'], 'it', $target);
    }

    public static function actionMessage(int $userId = 1, ChatReplyTarget $target = new ChatReplyTarget(ChatPlatform::Discord)): HandleChatActionMessage
    {
        return new HandleChatActionMessage($userId, ChatPlatform::Discord, 'd1', new ChatActionRef('refresh', ['42']), 'it', $target);
    }

    public function testCommandRunsWithTheRequestOfTheMessage(): void
    {
        $result = $this->kit->executor()->executeCommand(self::commandMessage());

        $this->assertFalse($result->failed);
        $this->assertSame('hi', $result->reply->text);
        $request = $this->kit->echo->requests[0];
        $this->assertSame(1, $request->user->user->getId());
        $this->assertSame('d1', $request->user->platformUserId);
        $this->assertSame(ChatPlatform::Discord, $request->platform);
        $this->assertSame(['text' => 'hi'], $request->arguments);
        $this->assertSame('it', $request->locale);
    }

    public function testExceptionIsAGenericErrorLoggedByClassAndCodeOnly(): void
    {
        $this->kit->echo->exception = new \RuntimeException('Invalid cookie ltoken_v2=secret-cookie', 42);

        $result = $this->kit->executor()->executeCommand(self::commandMessage());

        $this->assertTrue($result->failed);
        $this->assertSame(ChatReply::error()->text, $result->reply->text);
        [$level, , $context] = $this->kit->logger->records[0];
        $this->assertSame('error', $level);
        $this->assertSame(['command' => 'echo', 'subcommand' => null, 'platform' => 'discord', 'error' => \RuntimeException::class, 'code' => 42], $context);
        $this->assertStringNotContainsString('secret', $this->kit->logger->dump());
        $this->assertArrayNotHasKey('exception', $context);
    }

    public function testEmptyReplyIsAnError(): void
    {
        $this->kit->echo->reply = new ChatReply(' ');

        $result = $this->kit->executor()->executeCommand(self::commandMessage());

        $this->assertTrue($result->failed);
        $this->assertSame(ChatReply::error()->text, $result->reply->text);
        $this->assertSame('error', $this->kit->logger->records[0][0]);
    }

    public function testUserDisabledSinceTheRequestIsRefused(): void
    {
        $this->kit->user(1, disabled: true);

        $result = $this->kit->executor()->executeCommand(self::commandMessage());

        $this->assertTrue($result->failed);
        $this->assertSame(ChatReply::error(ChatUserResolver::DISABLED)->text, $result->reply->text);
        $this->assertSame([], $this->kit->echo->requests);
    }

    public function testUserDeletedOrUnlinkedSinceTheRequestIsRefused(): void
    {
        $executor = $this->kit->executor();

        $deleted = $executor->executeCommand(self::commandMessage(userId: 7));
        $this->assertTrue($deleted->failed);
        $this->assertSame(ChatReply::error(ChatExecutor::NOT_LINKED_ANYMORE)->text, $deleted->reply->text);

        // Another Discord account linked in the meantime
        $this->assertTrue($executor->executeCommand(self::commandMessage(platformUserId: 'd2'))->failed);
        $this->assertSame([], $this->kit->echo->requests);
    }

    public function testCommandRemovedSinceTheRequestIsAnError(): void
    {
        $result = $this->kit->executor()->executeCommand(self::commandMessage(command: 'gone'));

        $this->assertTrue($result->failed);
        $this->assertSame(ChatReply::error()->text, $result->reply->text);
    }

    public function testActionIsHandledAndItsFailureFlagged(): void
    {
        $executor = $this->kit->executor();

        $result = $executor->handleAction(self::actionMessage());
        $this->assertFalse($result->failed);
        $this->assertSame('refreshed 42', $result->reply->text);

        $this->kit->refresh->exception = new \LogicException('secret');
        $failed = $executor->handleAction(self::actionMessage());
        $this->assertTrue($failed->failed);
        $this->assertSame(ChatReply::error()->text, $failed->reply->text);
        $this->assertSame(['action' => 'refresh', 'platform' => 'discord', 'error' => \LogicException::class, 'code' => 0], $this->kit->logger->records[0][2]);
    }
}

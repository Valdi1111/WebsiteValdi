<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Exception\ChatReplyNotSentException;
use App\CoreBundle\MessageHandler\Chat\ExecuteChatCommandHandler;
use App\CoreBundle\MessageHandler\Chat\HandleChatActionHandler;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatReplyMode;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Service\Chat\ChatReplyDelivery;
use App\Tests\CoreBundle\Chat\Fixtures\RecordingChatReplySender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class ChatMessageHandlersTest extends TestCase
{
    private const string NOW = '2026-11-27 10:00:00';

    private ChatTestKit $kit;
    private RecordingChatReplySender $sender;
    private ChatReplyTarget $target;

    protected function setUp(): void
    {
        $this->kit = new ChatTestKit();
        $this->kit->user(1);
        $this->sender = new RecordingChatReplySender(ChatPlatform::Discord);
        $this->target = self::target('-1 minute');
    }

    private static function target(string $age, ChatPlatform $platform = ChatPlatform::Discord): ChatReplyTarget
    {
        return new ChatReplyTarget($platform, ['token' => 'secret-token'], new \DateTimeImmutable(self::NOW)->modify($age));
    }

    private function delivery(): ChatReplyDelivery
    {
        return new ChatReplyDelivery([new RecordingChatReplySender(ChatPlatform::Telegram), $this->sender], new MockClock(new \DateTimeImmutable(self::NOW)), $this->kit->logger);
    }

    private function commandHandler(): ExecuteChatCommandHandler
    {
        return new ExecuteChatCommandHandler($this->kit->executor(), $this->delivery(), $this->kit->logger);
    }

    private function actionHandler(): HandleChatActionHandler
    {
        return new HandleChatActionHandler($this->kit->executor(), $this->delivery(), $this->kit->logger);
    }

    public function testCommandAnswerReplacesTheDeferredAnswer(): void
    {
        ($this->commandHandler())(ChatExecutorTest::commandMessage(target: $this->target));

        $this->assertCount(1, $this->sender->sent);
        [$target, $reply, $mode] = $this->sender->sent[0];
        $this->assertSame($this->target, $target);
        $this->assertSame('hi', $reply->text);
        $this->assertSame(ChatReplyMode::Replace, $mode);
    }

    public function testFailedCommandAlsoReplacesTheDeferredAnswer(): void
    {
        $this->kit->echo->exception = new \RuntimeException('boom');

        ($this->commandHandler())(ChatExecutorTest::commandMessage(target: $this->target));

        [, $reply, $mode] = $this->sender->sent[0];
        $this->assertSame(ChatReply::error()->text, $reply->text);
        $this->assertSame(ChatReplyMode::Replace, $mode);
    }

    public function testActionAnswerReplacesTheMessageAndItsFailureIsAFollowUp(): void
    {
        $handler = $this->actionHandler();

        $handler(ChatExecutorTest::actionMessage(target: $this->target));
        $this->kit->refresh->exception = new \RuntimeException('boom');
        $handler(ChatExecutorTest::actionMessage(target: $this->target));

        $this->assertSame('refreshed 42', $this->sender->sent[0][1]->text);
        $this->assertSame(ChatReplyMode::Replace, $this->sender->sent[0][2]);
        $this->assertSame(ChatReply::error()->text, $this->sender->sent[1][1]->text);
        $this->assertSame(ChatReplyMode::FollowUp, $this->sender->sent[1][2]);
    }

    public function testRequestTooOldIsSkippedWithoutRunning(): void
    {
        ($this->commandHandler())(ChatExecutorTest::commandMessage(target: self::target('-14 minutes -1 second')));
        ($this->actionHandler())(ChatExecutorTest::actionMessage(target: self::target('-20 minutes')));

        $this->assertSame([], $this->sender->sent);
        $this->assertSame([], $this->kit->echo->requests);
        $this->assertSame([], $this->kit->refresh->handled);
        $this->assertSame(['warning', 'warning'], array_column($this->kit->logger->records, 0));

        // Still answered at 14 minutes
        ($this->commandHandler())(ChatExecutorTest::commandMessage(target: self::target('-14 minutes')));
        $this->assertCount(1, $this->sender->sent);
    }

    public function testSenderFailureIsLoggedWithTheStatusOnlyAndNotRethrown(): void
    {
        $this->sender->exception = new ChatReplyNotSentException('Discord refused https://discord.com/api/webhooks/1/secret-token', 404);

        ($this->commandHandler())(ChatExecutorTest::commandMessage(target: $this->target));

        [$level, , $context] = $this->kit->logger->records[0];
        $this->assertSame('error', $level);
        $this->assertSame(['platform' => 'discord', 'mode' => 'replace', 'error' => ChatReplyNotSentException::class, 'status' => 404], $context);
        $this->assertStringNotContainsString('secret', $this->kit->logger->dump());
    }

    public function testAnyOtherSenderExceptionIsSwallowedToo(): void
    {
        $this->sender->exception = new \TypeError('secret-token');

        ($this->actionHandler())(ChatExecutorTest::actionMessage(target: $this->target));

        $this->assertSame(\TypeError::class, $this->kit->logger->records[0][2]['error']);
        $this->assertStringNotContainsString('secret', $this->kit->logger->dump());
    }

    public function testPlatformWithoutSenderIsLogged(): void
    {
        $delivery = new ChatReplyDelivery([$this->sender], new MockClock(new \DateTimeImmutable(self::NOW)), $this->kit->logger);

        $this->assertFalse($delivery->send(self::target('-1 minute', ChatPlatform::Telegram), ChatReply::text('hi'), ChatReplyMode::Replace));
        $this->assertSame([], $this->sender->sent);
        $this->assertSame([['error', 'No chat reply sender for {platform}', ['platform' => 'telegram', 'mode' => 'replace']]], $this->kit->logger->records);
        $this->assertTrue($delivery->send($this->target, ChatReply::text('hi'), ChatReplyMode::FollowUp));
    }
}

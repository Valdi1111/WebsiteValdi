<?php

namespace App\Tests\CoreBundle\Telegram\Outbound;

use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReplySenderInterface;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\CoreBundle\Service\Chat\ChatReplyDelivery;
use App\CoreBundle\Service\Chat\Telegram\TelegramReplySender;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The Telegram answers and command menu as the container builds them, over the commands really registered. No
 * database, no call to Telegram.
 */
class TelegramOutboundWiringTest extends KernelTestCase
{
    public function testTheWorkerSendsTelegramAnswersThroughTheTelegramSender(): void
    {
        $senders = new \ReflectionProperty(ChatReplyDelivery::class, 'senders')->getValue(static::getContainer()->get(ChatReplyDelivery::class));

        $telegram = array_values(array_filter([...$senders], static fn (ChatReplySenderInterface $sender): bool => $sender->supports(ChatPlatform::Telegram)));
        $this->assertCount(1, $telegram);
        $this->assertInstanceOf(TelegramReplySender::class, $telegram[0]);
    }

    public function testTheMenusListEveryCommandInEnglishAndItalian(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('chat:commands:sync'));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--platform' => 'telegram', '--dry-run' => true]));

        $menus = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        $names = static::getContainer()->get(ChatCommandRegistry::class)->getNames();
        $this->assertCount(2, $menus);
        $this->assertSame($names, array_column($menus[0]['commands'], 'command'));
        $this->assertSame($names, array_column($menus[1]['commands'], 'command'));
        $this->assertSame('it', $menus[1]['language_code']);
        // Every description has its Italian text (ChatWiringTest)
        $this->assertSame([], array_intersect_assoc(array_column($menus[0]['commands'], 'description'), array_column($menus[1]['commands'], 'description')));
    }
}

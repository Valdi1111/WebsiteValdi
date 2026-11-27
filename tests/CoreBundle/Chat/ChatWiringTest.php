<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Chat\Command\HelpChatCommand;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Message\Chat\ExecuteChatCommandMessage;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatDispatcherInterface;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReplyTarget;
use App\CoreBundle\Repository\RoleRepository;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatActionRegistry;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\Tests\CoreBundle\InMemoryUserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The chat framework as the container builds it, over the commands and buttons really registered: each one is valid
 * on every platform, and a command goes through the real dispatcher to the core_chat queue. No database: the users
 * are in memory and the role hierarchy is empty.
 */
class ChatWiringTest extends KernelTestCase
{
    private InMemoryUserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->users = new InMemoryUserRepository();
        static::getContainer()->set(UserRepository::class, $this->users);
        static::getContainer()->set(RoleRepository::class, $this->createStub(RoleRepository::class));
        static::getContainer()->get('core.security_hierarchy.cache')->clear();
    }

    private function user(): User
    {
        $user = new User()->setEmail('me@example.test')->setPassword('$2y$04$hash')->setDiscordUserId('123456');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);

        return $this->users->add($user);
    }

    public function testEveryRegisteredCommandHasAValidDefinition(): void
    {
        $commands = static::getContainer()->get(ChatCommandRegistry::class);

        $this->assertContains('help', $commands->getNames());
        foreach ($commands->getDefinitions() as $definition) {
            // Built by ChatCommandBuilder, which throws on any broken rule
            $this->assertSame($definition, $commands->getDefinition($definition->name));
        }
        // The metadata read from the attribute of the class the container gives
        $this->assertSame('List the commands you can use', $commands->getDefinition('help')->description);
        $this->assertInstanceOf(HelpChatCommand::class, $commands->get('help'));
    }

    public function testEveryRegisteredActionFitsTheButtonData(): void
    {
        $codec = static::getContainer()->get(ChatActionCodec::class);
        $names = static::getContainer()->get(ChatActionRegistry::class)->getNames();

        $this->assertIsList($names);
        foreach ($names as $name) {
            // Throws over 64 bytes; the arguments need room too
            $this->assertLessThan(ChatActionCodec::MAX_BYTES / 2, strlen($codec->encode(new ChatActionRef($name))), $name);
        }
    }

    public function testEveryDescriptionHasItsItalianTranslation(): void
    {
        $translator = static::getContainer()->get('translator');
        $this->assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('it');

        $descriptions = [];
        foreach (static::getContainer()->get(ChatCommandRegistry::class)->getDefinitions() as $definition) {
            $descriptions[] = $definition->description;
            foreach ([...$definition->options, ...$definition->subcommands] as $item) {
                $descriptions[] = $item->description;
            }
            foreach ($definition->subcommands as $subcommand) {
                foreach ($subcommand->options as $option) {
                    $descriptions[] = $option->description;
                }
            }
        }

        $this->assertContains('List the commands you can use', $descriptions);
        foreach ($descriptions as $description) {
            $this->assertTrue($catalogue->has($description, 'chat'), sprintf('No Italian text for "%s" in translations/chat.it.yaml.', $description));
        }
        $this->assertSame('Elenca i comandi che puoi usare', $translator->trans('List the commands you can use', [], 'chat', 'it'));
    }

    public function testCommandIsQueuedOnCoreChat(): void
    {
        $this->user();
        $dispatcher = static::getContainer()->get(ChatDispatcherInterface::class);
        $this->assertInstanceOf(ChatDispatcher::class, $dispatcher);

        $result = $dispatcher->dispatchCommand(ChatPlatform::Discord, '123456', 'help', null, [], 'it', new ChatReplyTarget(ChatPlatform::Discord, ['token' => 't']));

        $this->assertTrue($result->isDeferred());
        $transport = static::getContainer()->get('messenger.transport.core_chat');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(ExecuteChatCommandMessage::class, $sent[0]->getMessage());
        $this->assertSame(42, $sent[0]->getMessage()->userId);
    }

    public function testNotLinkedAnswerLinksToTheProfileOnTheMainDomain(): void
    {
        $result = static::getContainer()->get(ChatDispatcherInterface::class)
            ->dispatchCommand(ChatPlatform::Discord, '999', 'help', null, [], 'en', new ChatReplyTarget(ChatPlatform::Discord));

        $this->assertStringEndsWith('https://' . static::getContainer()->getParameter('domain_name') . '/profile', (string) $result->reply?->text);
    }

    public function testChatRunPrintsTheHelp(): void
    {
        $this->user();
        $tester = new CommandTester(new Application(self::$kernel)->find('chat:run'));

        $this->assertSame(Command::SUCCESS, $tester->execute(['user' => 'me@example.test', 'line' => '/help', '--locale' => 'it']));
        $this->assertStringContainsString('/help · Elenca i comandi che puoi usare', $tester->getDisplay());
    }
}

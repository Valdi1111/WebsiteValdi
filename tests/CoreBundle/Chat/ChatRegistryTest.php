<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Exception\InvalidChatActionRefException;
use App\CoreBundle\Exception\InvalidChatCommandException;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Service\Chat\ChatActionRegistry;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\Tests\CoreBundle\Chat\Fixtures\AdminChatCommand;
use App\Tests\CoreBundle\Chat\Fixtures\EchoChatCommand;
use App\Tests\CoreBundle\Chat\Fixtures\RemindChatCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

class ChatRegistryTest extends TestCase
{
    private ChatTestKit $kit;

    protected function setUp(): void
    {
        $this->kit = new ChatTestKit();
    }

    public function testNamesAndDefinitionsSortedByName(): void
    {
        $commands = $this->kit->commands();

        $this->assertSame(['admin', 'echo', 'help', 'remind'], $commands->getNames());
        $this->assertSame(['admin', 'echo', 'help', 'remind'], array_map(static fn ($definition): string => $definition->name, $commands->getDefinitions()));
        $this->assertTrue($commands->has('echo'));
        $this->assertFalse($commands->has('nope'));
        $this->assertSame($this->kit->echo, $commands->get('echo'));
    }

    public function testDefinitionFromTheAttributeAndConfigureBuiltOnce(): void
    {
        $commands = $this->kit->commands();

        $definition = $commands->getDefinition('remind');
        $this->assertSame('Manage your reminders', $definition->description);
        $this->assertSame('ROLE_GAMES', $definition->role);
        $this->assertTrue($definition->ephemeral);
        $this->assertSame(['stamina', 'list'], array_map(static fn ($subcommand): string => $subcommand->name, $definition->subcommands));
        $this->assertFalse($commands->getDefinition('echo')->ephemeral);
        $this->assertSame($definition, $commands->getDefinition('remind'));

        $this->expectException(InvalidChatCommandException::class);
        $commands->getDefinition('nope');
    }

    public function testCommandsFilteredByTheRolesOfTheUser(): void
    {
        $commands = $this->kit->commands();
        $player = $this->kit->user(1, ['ROLE_GAMES', 'ROLE_USER']);
        $nobody = $this->kit->user(2);

        $this->assertSame(['echo', 'help', 'remind'], array_map(static fn ($definition): string => $definition->name, $commands->getDefinitionsFor($player)));
        $this->assertSame(['echo', 'help'], array_map(static fn ($definition): string => $definition->name, $commands->getDefinitionsFor($nobody)));
        // Read once per list, not once per command
        $this->assertSame([1, 2], $this->kit->roleReads);

        $this->assertTrue($commands->isGranted($player, 'remind'));
        $this->assertFalse($commands->isGranted($nobody, 'remind'));
        $this->assertFalse($commands->isGranted($player, 'admin'));
        $this->assertTrue($commands->isGranted($nobody, 'echo'));
        $this->assertFalse($commands->isGranted($player, 'nope'));
    }

    public function testRolesAreNotReadWhenNoCommandNeedsOne(): void
    {
        $commands = new ChatCommandRegistry(new ServiceLocator(['echo' => fn (): EchoChatCommand => $this->kit->echo]), $this->kit->roleResolver());

        $this->assertCount(1, $commands->getDefinitionsFor($this->kit->user(1)));
        $this->assertTrue($commands->isGranted($this->kit->user(2), 'echo'));
        $this->assertSame([], $this->kit->roleReads);
    }

    /**
     * With the class in the type of the factory, as the container gives it, a list builds only the commands it keeps.
     */
    public function testListingBuildsOnlyTheCommandsItKeeps(): void
    {
        $built = [];
        $commands = new ChatCommandRegistry(new ServiceLocator([
            'echo' => function () use (&$built): EchoChatCommand {
                $built[] = 'echo';

                return $this->kit->echo;
            },
            'remind' => function () use (&$built): RemindChatCommand {
                $built[] = 'remind';

                return $this->kit->remind;
            },
            'admin' => static function () use (&$built): AdminChatCommand {
                $built[] = 'admin';

                return new AdminChatCommand();
            },
        ]), $this->kit->roleResolver());

        $this->assertSame(['admin', 'echo', 'remind'], $commands->getNames());
        $this->assertSame([], $built);

        $this->assertFalse($commands->isGranted($this->kit->user(1), 'admin'));
        $commands->getDefinitionsFor($this->kit->user(1, ['ROLE_GAMES']));
        $this->assertSame(['echo', 'remind'], $built);
    }

    public function testCommandWithoutTheAttributeIsAnError(): void
    {
        $commands = new ChatCommandRegistry(new ServiceLocator([
            'bare' => static fn () => new class implements ChatCommandInterface {
                public function configure(ChatCommandBuilder $builder): void
                {
                }

                public function execute(ChatRequest $request): ChatReply
                {
                    return ChatReply::text('bare');
                }
            },
        ]), $this->kit->roleResolver());

        $this->expectException(InvalidChatCommandException::class);
        $this->expectExceptionMessage('has no #[AsChatCommand]');
        $commands->getNames();
    }

    public function testServiceThatIsNotACommandIsAnError(): void
    {
        $commands = new ChatCommandRegistry(new ServiceLocator(['odd' => static fn () => new \stdClass()]), $this->kit->roleResolver());

        $this->expectException(InvalidChatCommandException::class);
        $this->expectExceptionMessage('does not implement');
        $commands->get('odd');
    }

    public function testActionHandlersByName(): void
    {
        $actions = new ChatActionRegistry(new ServiceLocator([
            'refresh' => fn () => $this->kit->refresh,
            'page' => fn () => $this->kit->refresh,
            'odd' => static fn () => new \stdClass(),
        ]));

        $this->assertSame(['odd', 'page', 'refresh'], $actions->getNames());
        $this->assertTrue($actions->has('refresh'));
        $this->assertFalse($actions->has('snooze'));
        $this->assertSame($this->kit->refresh, $actions->get('refresh'));

        $this->expectException(InvalidChatActionRefException::class);
        $actions->get('odd');
    }
}

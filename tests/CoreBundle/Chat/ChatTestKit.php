<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Chat\Command\HelpChatCommand;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatActionRegistry;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\CoreBundle\Service\Chat\ChatExecutor;
use App\CoreBundle\Service\Chat\ChatUserResolver;
use App\CoreBundle\Service\UserRoleResolver;
use App\Tests\CoreBundle\Chat\Fixtures\AdminChatCommand;
use App\Tests\CoreBundle\Chat\Fixtures\EchoChatCommand;
use App\Tests\CoreBundle\Chat\Fixtures\RecordingLogger;
use App\Tests\CoreBundle\Chat\Fixtures\RecordingMessageBus;
use App\Tests\CoreBundle\Chat\Fixtures\RefreshChatAction;
use App\Tests\CoreBundle\Chat\Fixtures\RemindChatCommand;
use App\Tests\CoreBundle\InMemoryUserRepository;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The chat framework built by hand around the fixture commands (echo, remind, admin, help) and button (refresh), with
 * users in memory, roles by user id and a recording bus and logger: no container, no database.
 */
final class ChatTestKit
{
    public const string DEFAULT_URI = 'https://valdi.test';

    public InMemoryUserRepository $users;
    /** @var array<int, list<string>> reachable roles by user id */
    public array $roles = [];
    /** @var list<int> users whose roles were read */
    public array $roleReads = [];

    public EchoChatCommand $echo;
    public RemindChatCommand $remind;
    public AdminChatCommand $admin;
    public RefreshChatAction $refresh;

    public RecordingMessageBus $bus;
    public RecordingLogger $logger;
    public Translator $translator;

    private ?ChatCommandRegistry $commands = null;

    public function __construct()
    {
        $this->users = new InMemoryUserRepository();
        $this->echo = new EchoChatCommand();
        $this->remind = new RemindChatCommand();
        $this->admin = new AdminChatCommand();
        $this->refresh = new RefreshChatAction();
        $this->bus = new RecordingMessageBus();
        $this->logger = new RecordingLogger();

        // The real Italian descriptions
        $this->translator = new Translator('en');
        $this->translator->addLoader('yaml', new YamlFileLoader());
        $this->translator->addResource('yaml', dirname(__DIR__, 3) . '/src/CoreBundle/translations/chat.it.yaml', 'it', 'chat');
    }

    /**
     * A user linked on Discord ("d<id>") and Telegram ("t<id>"), saved in the in-memory repository.
     *
     * @param list<string> $roles reachable roles
     */
    public function user(int $id, array $roles = [], bool $disabled = false): User
    {
        $user = new User()->setEmail(sprintf('user%d@example.test', $id))->setDiscordUserId('d' . $id)->setTelegramChatId('t' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        if ($disabled) {
            $user->setDisabledAt(new \DateTimeImmutable('2026-11-01'));
        }
        $this->roles[$id] = $roles;

        return $this->users->add($user);
    }

    public function roleResolver(): UserRoleResolver
    {
        $roles = fn (User $user): array => $this->roles[(int) $user->getId()] ?? [];
        $reads = function (User $user): void {
            $this->roleReads[] = (int) $user->getId();
        };

        return new class($roles, $reads) extends UserRoleResolver {
            /** @noinspection PhpMissingParentConstructorInspection the parent reads the database */
            public function __construct(private readonly \Closure $roles, private readonly \Closure $reads)
            {
            }

            public function getReachableRoleNames(User $user): array
            {
                ($this->reads)($user);

                return ($this->roles)($user);
            }
        };
    }

    public function commands(): ChatCommandRegistry
    {
        // Untyped factories: the registry then reads the attribute from the class of the instance
        return $this->commands ??= new ChatCommandRegistry(new ServiceLocator([
            'echo' => fn () => $this->echo,
            'remind' => fn () => $this->remind,
            'admin' => fn () => $this->admin,
            'help' => fn () => new HelpChatCommand($this->commands(), $this->translator),
        ]), $this->roleResolver());
    }

    public function actions(): ChatActionRegistry
    {
        return new ChatActionRegistry(new ServiceLocator(['refresh' => fn () => $this->refresh]));
    }

    public function userResolver(): ChatUserResolver
    {
        return new ChatUserResolver($this->users);
    }

    public function executor(): ChatExecutor
    {
        return new ChatExecutor($this->userResolver(), $this->commands(), $this->actions(), $this->logger);
    }

    public function dispatcher(): ChatDispatcher
    {
        $routes = new RouteCollection();
        $routes->add(ChatDispatcher::PROFILE_ROUTE, new Route('/profile/{path}', ['path' => ''], ['path' => '.*']));

        return new ChatDispatcher(
            $this->userResolver(),
            $this->commands(),
            $this->actions(),
            new ChatActionCodec(),
            $this->executor(),
            $this->bus,
            new UrlGenerator($routes, new RequestContext()),
            self::DEFAULT_URI,
            $this->logger,
        );
    }
}

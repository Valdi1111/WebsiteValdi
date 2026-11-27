<?php

namespace App\CoreBundle\Service\Chat;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\InvalidChatCommandException;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandDefinition;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Service\UserRoleResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The chat commands (#[AsChatCommand]) by name, for every platform.
 *
 * Name, role and visibility are read from the attribute of the class the locator provides, so a list filtered by role
 * builds only the commands it keeps; a definition (configure()) is built once per command.
 */
class ChatCommandRegistry
{
    /** @var array<string, AsChatCommand>|null attributes by command name, sorted by name */
    private ?array $attributes = null;

    /** @var array<string, ChatCommandDefinition> */
    private array $definitions = [];

    /**
     * @param ServiceProviderInterface<ChatCommandInterface> $commands
     */
    public function __construct(
        #[AutowireLocator(AsChatCommand::TAG, indexAttribute: 'name')]
        private readonly ServiceProviderInterface $commands,
        // Lazy: the role hierarchy reads the database when built, which chat:run --help and the tests must not do
        #[Autowire(lazy: true)]
        private readonly UserRoleResolver $roleResolver,
    ) {
    }

    public function has(string $name): bool
    {
        return $this->commands->has($name);
    }

    /**
     * @throws InvalidChatCommandException when the service is not a chat command
     */
    public function get(string $name): ChatCommandInterface
    {
        $command = $this->commands->get($name);
        if (!$command instanceof ChatCommandInterface) {
            throw new InvalidChatCommandException(sprintf('Chat command "%s" (%s) does not implement %s.', $name, get_debug_type($command), ChatCommandInterface::class));
        }

        return $command;
    }

    /**
     * @return list<string> sorted
     */
    public function getNames(): array
    {
        return array_map(strval(...), array_keys($this->getAttributes()));
    }

    /**
     * @throws InvalidChatCommandException when the command is unknown or its definition breaks a platform rule
     */
    public function getDefinition(string $name): ChatCommandDefinition
    {
        if (isset($this->definitions[$name])) {
            return $this->definitions[$name];
        }

        $attribute = $this->getAttributes()[$name] ?? throw new InvalidChatCommandException(sprintf('Unknown chat command "%s".', $name));
        $builder = new ChatCommandBuilder($name, $attribute->description, $attribute->role, $attribute->ephemeral);
        $this->get($name)->configure($builder);

        return $this->definitions[$name] = $builder->getDefinition();
    }

    /**
     * @return list<ChatCommandDefinition> every command, sorted by name (to register them on the platforms)
     */
    public function getDefinitions(): array
    {
        return array_map($this->getDefinition(...), $this->getNames());
    }

    /**
     * @return list<ChatCommandDefinition> the commands the user has the role of, sorted by name
     */
    public function getDefinitionsFor(User $user): array
    {
        $roles = null;
        $definitions = [];
        foreach ($this->getAttributes() as $name => $attribute) {
            // The roles are read only when a command needs one
            if ($attribute->role === null || in_array($attribute->role, $roles ??= $this->roleResolver->getReachableRoleNames($user), true)) {
                $definitions[] = $this->getDefinition((string) $name);
            }
        }

        return $definitions;
    }

    /**
     * Whether the user has the role of the command, assigned or inherited; a command without a role is for every user.
     */
    public function isGranted(User $user, string $name): bool
    {
        $role = ($this->getAttributes()[$name] ?? null)?->role;

        return $this->has($name) && ($role === null || in_array($role, $this->roleResolver->getReachableRoleNames($user), true));
    }

    /**
     * @return array<string, AsChatCommand>
     */
    private function getAttributes(): array
    {
        if ($this->attributes === null) {
            $this->attributes = [];
            foreach ($this->commands->getProvidedServices() as $name => $type) {
                $this->attributes[(string) $name] = $this->readAttribute((string) $name, ltrim($type, '?'));
            }
            ksort($this->attributes);
        }

        return $this->attributes;
    }

    private function readAttribute(string $name, string $class): AsChatCommand
    {
        // The container gives the class of each service; a locator built without types gives "?" or an interface
        if (!class_exists($class)) {
            $class = $this->get($name)::class;
        }
        $attributes = new \ReflectionClass($class)->getAttributes(AsChatCommand::class);
        if ($attributes === []) {
            throw new InvalidChatCommandException(sprintf('Chat command "%s" (%s) has no #[AsChatCommand].', $name, $class));
        }

        return $attributes[0]->newInstance();
    }
}

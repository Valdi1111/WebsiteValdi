<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\Role;
use App\CoreBundle\Entity\RoleHierarchy;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserRole;
use App\CoreBundle\Repository\RoleRepository;
use App\CoreBundle\Repository\UserAvatarRepository;
use App\CoreBundle\Repository\UserRepository;
use App\Tests\CoreBundle\InMemoryUserAvatarRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * HTTP requests of a logged-in user through the whole stack (firewall, IsGranted with the database role hierarchy),
 * for a WebTestCase, without a database: the suite runs without one.
 *
 * The repositories the requests read are replaced in the test container: UserRepository to refresh the logged-in
 * user from the session, RoleRepository for the role hierarchy and the role details, from the catalog in $roles,
 * and UserAvatarRepository by the in-memory $avatars. The hierarchy cache is cleared, so no map built by another
 * test or run is reused.
 *
 * The user is not managed by Doctrine, so a flush() during the request writes nothing: assert on the User instance.
 */
trait WithoutDatabaseClientTrait
{
    /** @var array<string, Role> role catalog of the fake database, by name */
    private array $roles = [];

    /** Profile images of the fake database */
    private InMemoryUserAvatarRepository $avatars;

    private static function role(string $name, string $description, ?string $bundle, Role ...$children): Role
    {
        $role = (new Role())->setName($name)->setDescription($description);
        if ($bundle !== null) {
            $role->setBundle($bundle);
        }
        foreach ($children as $child) {
            $role->addChildRole((new RoleHierarchy())->setChildRole($child));
        }

        return $role;
    }

    private function createClientWithoutDatabase(): KernelBrowser
    {
        $client = static::createClient();
        // Keeps the replaced services between requests
        $client->disableReboot();

        $roleRepository = $this->createStub(RoleRepository::class);
        $roleRepository->method('findRolesWithChildren')
            ->willReturn(array_values(array_filter($this->roles, static fn (Role $role): bool => !$role->getChildRoles()->isEmpty())));
        $roleRepository->method('findBy')
            ->willReturnCallback(fn (array $criteria): array => array_values(array_filter(
                $this->roles,
                static fn (Role $role): bool => in_array($role->getName(), $criteria['name'], true),
            )));
        // As MySQL sorts: no bundle first, then by bundle and name
        $roleRepository->method('findAllSorted')
            ->willReturnCallback(function (): array {
                $roles = array_values($this->roles);
                usort($roles, static fn (Role $a, Role $b): int => [$a->getBundle() ?? '', $a->getName()] <=> [$b->getBundle() ?? '', $b->getName()]);

                return $roles;
            });
        static::getContainer()->set(RoleRepository::class, $roleRepository);
        static::getContainer()->get('core.security_hierarchy.cache')->clear();

        $this->avatars = new InMemoryUserAvatarRepository();
        static::getContainer()->set(UserAvatarRepository::class, $this->avatars);

        return $client;
    }

    /**
     * @param list<string> $roleNames assigned roles, from the catalog or not
     */
    private function createUser(array $roleNames): User
    {
        $user = (new User())->setEmail('me@example.test')->setPassword('$2y$04$hash')->setDiscordUserId('123456')->setTelegramChatId('654321');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);
        foreach ($roleNames as $roleName) {
            $user->addAssignedRole((new UserRole())->setRole($this->roles[$roleName] ?? (new Role())->setName($roleName)));
        }

        return $user;
    }

    private function logIn(KernelBrowser $client, User $user): void
    {
        // The firewall reloads the user of the session by id
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);
        static::getContainer()->set(UserRepository::class, $userRepository);

        $client->loginUser($user);
    }

    private static function url(string $hostParameter, string $path): string
    {
        return 'https://'.static::getContainer()->getParameter($hostParameter).$path;
    }

    /**
     * @return array<mixed>
     */
    private static function jsonResponse(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true);
    }
}

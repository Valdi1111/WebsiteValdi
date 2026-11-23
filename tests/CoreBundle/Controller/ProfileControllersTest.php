<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\Role;
use App\CoreBundle\Entity\RoleHierarchy;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserRole;
use App\CoreBundle\Repository\RoleRepository;
use App\CoreBundle\Repository\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/me, GET /api/me/roles and GET /profile through the whole HTTP stack
 * (firewall, IsGranted with the database role hierarchy, routing on every subdomain).
 *
 * The suite runs without a database: the repositories the security layer reads are replaced in the test container,
 * UserRepository to refresh the logged-in user from the session and RoleRepository for the role hierarchy and the
 * role details. The hierarchy cache is cleared too, so no map built by another test or run is reused.
 */
class ProfileControllersTest extends WebTestCase
{
    /** @var array<string, Role> role catalog of the fake database, by name */
    private array $roles = [];

    /**
     * @return iterable<string, array{string}> the host parameters of the subdomains, and the main domain
     */
    public static function hostParameters(): iterable
    {
        yield 'anime' => ['anime.domain_name'];
        yield 'books' => ['books.domain_name'];
        yield 'games' => ['games.domain_name'];
        yield 'videos' => ['videos.domain_name'];
        yield 'main domain' => ['domain_name'];
    }

    protected function setUp(): void
    {
        // ROLE_ADMIN_BOOKS > ROLE_USER_BOOKS > ROLE_USER, ROLE_USER_GAMES > ROLE_USER, as in the migrations
        $user = self::role('ROLE_USER', 'User', null);
        $userBooks = self::role('ROLE_USER_BOOKS', 'User books', 'BooksBundle', $user);
        $adminBooks = self::role('ROLE_ADMIN_BOOKS', 'Admin books', 'BooksBundle', $userBooks);
        $userGames = self::role('ROLE_USER_GAMES', 'User games', 'GamesBundle', $user);
        $other = self::role('ROLE_SOMETHING_ELSE', 'Something else', null);

        foreach ([$user, $userBooks, $adminBooks, $userGames, $other] as $role) {
            $this->roles[$role->getName()] = $role;
        }
    }

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
        static::getContainer()->set(RoleRepository::class, $roleRepository);
        static::getContainer()->get('core.security_hierarchy.cache')->clear();

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

    #[DataProvider('hostParameters')]
    public function testApiMeReturnsTheLoggedInUserOnEverySubdomain(string $hostParameter): void
    {
        $client = $this->createClientWithoutDatabase();
        // ROLE_USER only through the hierarchy
        $this->logIn($client, $this->createUser(['ROLE_USER_GAMES', 'ROLE_ADMIN_BOOKS']));

        $client->request('GET', self::url($hostParameter, '/api/me'));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        // Only these keys: never the password hash or the notification ids. Roles: assigned and inherited
        $this->assertSame(
            ['id' => 42, 'email' => 'me@example.test', 'roles' => ['ROLE_ADMIN_BOOKS', 'ROLE_USER', 'ROLE_USER_BOOKS', 'ROLE_USER_GAMES']],
            self::jsonResponse($client),
        );
    }

    public function testApiMeRolesReturnsAssignedAndInheritedRolesWithTheirDetails(): void
    {
        $client = $this->createClientWithoutDatabase();
        $this->logIn($client, $this->createUser(['ROLE_ADMIN_BOOKS', 'ROLE_USER', 'ROLE_WITHOUT_ENTITY']));

        $client->request('GET', self::url('books.domain_name', '/api/me/roles'));

        $this->assertResponseIsSuccessful();
        $this->assertSame([
            ['name' => 'ROLE_ADMIN_BOOKS', 'label' => 'Admin books', 'bundle' => 'BooksBundle', 'assigned' => true],
            // Assigned and also inherited: assigned wins
            ['name' => 'ROLE_USER', 'label' => 'User', 'bundle' => null, 'assigned' => true],
            ['name' => 'ROLE_USER_BOOKS', 'label' => 'User books', 'bundle' => 'BooksBundle', 'assigned' => false],
            // No Role row: the code is the label
            ['name' => 'ROLE_WITHOUT_ENTITY', 'label' => 'ROLE_WITHOUT_ENTITY', 'bundle' => null, 'assigned' => true],
        ], self::jsonResponse($client));
    }

    public function testApiMeAnswers401JsonWhenNotLoggedIn(): void
    {
        $client = $this->createClientWithoutDatabase();

        $client->request('GET', self::url('games.domain_name', '/api/me'));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->assertSame(401, self::jsonResponse($client)['status'] ?? null);
    }

    public function testApiMeAnswers403JsonWithoutRoleUser(): void
    {
        $client = $this->createClientWithoutDatabase();
        $this->logIn($client, $this->createUser(['ROLE_SOMETHING_ELSE']));

        $client->request('GET', self::url('games.domain_name', '/api/me/roles'));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertSame(403, self::jsonResponse($client)['status'] ?? null);
    }

    #[DataProvider('hostParameters')]
    public function testProfilePageIsServedOnEverySubdomain(string $hostParameter): void
    {
        $client = $this->createClientWithoutDatabase();
        $this->logIn($client, $this->createUser(['ROLE_USER']));

        // Not the "/{path}" catch-all of the bundle app of that subdomain
        $client->request('GET', self::url($hostParameter, '/profile'));

        $this->assertResponseIsSuccessful();
        $this->assertSame('core_profile', $client->getRequest()->attributes->get('_route'));
        $this->assertSelectorTextSame('title', 'Profile');
        $this->assertSelectorExists('#root');
    }

    public function testProfilePageRedirectsToLoginWhenNotLoggedIn(): void
    {
        $client = $this->createClientWithoutDatabase();

        $client->request('GET', self::url('games.domain_name', '/profile'));

        $this->assertResponseRedirects('/login');
    }
}

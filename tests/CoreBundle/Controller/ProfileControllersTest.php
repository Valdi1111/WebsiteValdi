<?php

namespace App\Tests\CoreBundle\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/me, GET /api/me/roles and GET /profile through the whole HTTP stack
 * (firewall, IsGranted with the database role hierarchy, routing on every subdomain), without a database.
 */
class ProfileControllersTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

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
            ['id' => 42, 'email' => 'me@example.test', 'roles' => ['ROLE_ADMIN_BOOKS', 'ROLE_USER', 'ROLE_USER_BOOKS', 'ROLE_USER_GAMES'], 'avatar_url' => null],
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

    /**
     * @return iterable<string, array{string, string}> the host parameters of the subdomains and the main domain, and
     *                                                 the profile page with its tabs
     */
    public static function profilePages(): iterable
    {
        foreach (self::hostParameters() as $host => [$hostParameter]) {
            foreach (['/profile', '/profile/tokens'] as $path) {
                yield $host.' '.$path => [$hostParameter, $path];
            }
        }
    }

    #[DataProvider('profilePages')]
    public function testProfilePageIsServedOnEverySubdomain(string $hostParameter, string $path): void
    {
        $client = $this->createClientWithoutDatabase();
        $this->logIn($client, $this->createUser(['ROLE_USER']));

        // Not the "/{path}" catch-all of the bundle app of that subdomain; the tabs are routes of the React app
        $client->request('GET', self::url($hostParameter, $path));

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

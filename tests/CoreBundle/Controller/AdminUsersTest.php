<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\Token;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserRole;
use App\CoreBundle\Model\Discord\DiscordUser;
use App\CoreBundle\Model\Telegram\TelegramUser;
use App\CoreBundle\Repository\ResetPasswordRequestRepository;
use App\CoreBundle\Repository\TokenRepository;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Security\RememberMeTokenRevoker;
use App\CoreBundle\Service\Admin\AdminUserManager;
use App\CoreBundle\Service\Password\WelcomeLinkSender;
use App\Tests\CoreBundle\InMemoryResetPasswordRequestRepository;
use App\Tests\CoreBundle\InMemoryTokenRepository;
use App\Tests\CoreBundle\InMemoryUserRepository;
use App\Tests\CoreBundle\RecordingRememberMeTokenRevoker;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Users admin page (/admin/users) and its API (/api/admin), and the login of a disabled account, through the whole
 * HTTP stack (firewall, IsGranted with the database role hierarchy, MapRequestPayload, user checker), without a
 * database: users, reset links and API tokens are kept in memory, remember-me logins only recorded, and the emails
 * stay in the in-memory core_async transport.
 */
class AdminUsersTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private const string PASSWORD = 'Correct-Horse-Battery-Staple-42';
    private const string RESET_LINK = '#https://[^\s"<]+/reset-password/([A-Za-z0-9]{40})#';

    private InMemoryUserRepository $users;
    private InMemoryResetPasswordRequestRepository $resetRequests;
    private RecordingRememberMeTokenRevoker $rememberMeTokens;
    private InMemoryTokenRepository $tokens;
    /** The logged-in admin, me@example.test */
    private User $admin;
    /** Another user, with only ROLE_USER and no linked account */
    private User $member;

    protected function setUp(): void
    {
        $this->roles['ROLE_USER'] = self::role('ROLE_USER', 'User', null);
        $this->roles['ROLE_ADMIN'] = self::role('ROLE_ADMIN', 'Admin', null, $this->roles['ROLE_USER']);
        $this->roles['ROLE_USER_BOOKS'] = self::role('ROLE_USER_BOOKS', 'User books', 'BooksBundle', $this->roles['ROLE_USER']);
        $this->roles['ROLE_ADMIN_BOOKS'] = self::role('ROLE_ADMIN_BOOKS', 'Admin books', 'BooksBundle', $this->roles['ROLE_USER_BOOKS']);
    }

    private function createClientWithUsers(): KernelBrowser
    {
        $client = $this->createClientWithoutDatabase();
        $container = static::getContainer();

        // Linked from the profile page, with their names
        $this->admin = $this->createUser(['ROLE_ADMIN', 'ROLE_ADMIN_BOOKS'])
            ->linkDiscordAccount(new DiscordUser('123456', 'me_on_discord', 'Me', null))
            ->linkTelegramAccount(new TelegramUser('654321', 'me_on_telegram', 'Me'));
        $this->member = $this->createUser(['ROLE_USER'])->setEmail('member@example.test')
            ->unlinkDiscordAccount()
            ->unlinkTelegramAccount();
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->member, 7);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        foreach ([$this->admin, $this->member] as $user) {
            $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        }

        $this->users = new InMemoryUserRepository();
        $this->users->add($this->admin);
        $this->users->add($this->member);
        $container->set(UserRepository::class, $this->users);

        $this->resetRequests = new InMemoryResetPasswordRequestRepository();
        $container->set(ResetPasswordRequestRepository::class, $this->resetRequests);
        $this->rememberMeTokens = new RecordingRememberMeTokenRevoker();
        $container->set(RememberMeTokenRevoker::class, $this->rememberMeTokens);
        $this->tokens = new InMemoryTokenRepository();
        $container->set(TokenRepository::class, $this->tokens);
        // No login attempts counted by another test or run
        $container->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function createAdminClient(): KernelBrowser
    {
        $client = $this->createClientWithUsers();
        $client->loginUser($this->admin);

        return $client;
    }

    private static function apiUrl(string $path): string
    {
        return self::url('games.domain_name', '/api/admin'.$path);
    }

    /**
     * @return array<string, list<string>> violation messages by property path
     */
    private static function violations(KernelBrowser $client): array
    {
        $violations = [];
        foreach (self::jsonResponse($client)['violations'] ?? [] as $violation) {
            $violations[$violation['propertyPath']][] = $violation['title'];
        }

        return $violations;
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function adminApiRequests(): iterable
    {
        yield 'roles' => ['GET', '/roles', null];
        yield 'list' => ['GET', '/users', null];
        yield 'create' => ['POST', '/users', ['email' => 'new@example.test', 'password' => null, 'roles' => ['ROLE_ADMIN']]];
        yield 'disable' => ['PATCH', '/users/42', ['enabled' => false]];
        // The member making themselves an admin
        yield 'roles of a user' => ['PUT', '/users/7/roles', ['roles' => ['ROLE_USER', 'ROLE_ADMIN']]];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('adminApiRequests')]
    public function testTheApiAnswers401WhenNotLoggedIn(string $method, string $path, ?array $body): void
    {
        $client = $this->createClientWithUsers();

        $client->jsonRequest($method, self::apiUrl($path), $body ?? []);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->assertCount(2, $this->users->rows);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('adminApiRequests')]
    public function testTheApiAnswers403WithoutRoleAdmin(string $method, string $path, ?array $body): void
    {
        $client = $this->createClientWithUsers();
        // A bundle admin is not a site-wide admin
        $this->member->addAssignedRole((new UserRole())->setRole($this->roles['ROLE_ADMIN_BOOKS']));
        $client->loginUser($this->member);

        $client->jsonRequest($method, self::apiUrl($path), $body ?? []);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertCount(2, $this->users->rows);
        $this->assertFalse($this->admin->isDisabled());
        $this->assertNotContains('ROLE_ADMIN', $this->member->getRoles());
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('adminApiRequests')]
    public function testTheApiIsNotReachableWithAnApiTokenOfAnAdmin(string $method, string $path, ?array $body): void
    {
        $client = $this->createClientWithUsers();
        $token = Token::createFor($this->admin, 'Script');
        $this->tokens->save($token);

        $client->request($method, self::apiUrl($path), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token->getAccessToken(),
            'CONTENT_TYPE' => 'application/json',
        ], $body === null ? null : json_encode($body));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertSame('Manage the users from the admin page, not with an API token.', self::jsonResponse($client)['detail']);
        $this->assertCount(2, $this->users->rows);
    }

    public function testThePageIsServedToAdminsOnEverySubdomain(): void
    {
        $client = $this->createAdminClient();

        foreach (['domain_name', 'games.domain_name', 'books.domain_name'] as $hostParameter) {
            // Not the "/{path}" catch-all of the bundle app of that subdomain; /admin/users is a route of the React app
            $client->request('GET', self::url($hostParameter, '/admin/users'));

            $this->assertResponseIsSuccessful();
            $this->assertSame('core_admin', $client->getRequest()->attributes->get('_route'));
            $this->assertSelectorTextSame('title', 'Admin');
            $this->assertSelectorExists('#root');
        }
    }

    public function testThePageRedirectsToLoginWhenNotLoggedIn(): void
    {
        $client = $this->createClientWithUsers();

        $client->request('GET', self::url('games.domain_name', '/admin/users'));

        $this->assertResponseRedirects('/login');
    }

    public function testThePageSendsTheUsersWithoutRoleAdminToTheLoginPage(): void
    {
        $client = $this->createClientWithUsers();
        $client->loginUser($this->member);

        $client->request('GET', self::url('games.domain_name', '/admin/users'));

        // AccessDeniedHandler: the pages redirect with a flash message, the API answers 403
        $this->assertResponseRedirects('/login');
    }

    public function testListsTheUsersWithTheirAssignedRolesAndLinkedAccounts(): void
    {
        $client = $this->createAdminClient();
        $this->member->setDisabledAt(new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC')));

        $client->request('GET', self::apiUrl('/users'));

        $this->assertResponseIsSuccessful();
        $this->assertSame([
            [
                'id' => 42,
                'email' => 'me@example.test',
                // Assigned, then with ROLE_USER and ROLE_USER_BOOKS, which they bring
                'roles' => ['ROLE_ADMIN', 'ROLE_ADMIN_BOOKS'],
                'effective_roles' => ['ROLE_ADMIN', 'ROLE_ADMIN_BOOKS', 'ROLE_USER', 'ROLE_USER_BOOKS'],
                'discord_linked' => true,
                'discord_username' => 'me_on_discord',
                'telegram_linked' => true,
                'telegram_username' => 'me_on_telegram',
                'enabled' => true,
                'disabled_at' => null,
            ],
            [
                'id' => 7,
                'email' => 'member@example.test',
                'roles' => ['ROLE_USER'],
                'effective_roles' => ['ROLE_USER'],
                'discord_linked' => false,
                'discord_username' => null,
                'telegram_linked' => false,
                'telegram_username' => null,
                'enabled' => false,
                'disabled_at' => '2026-11-20T10:00:00+00:00',
            ],
        ], self::jsonResponse($client));
    }

    public function testListsEveryRoleSiteWideFirst(): void
    {
        $client = $this->createAdminClient();

        $client->request('GET', self::apiUrl('/roles'));

        $this->assertResponseIsSuccessful();
        $this->assertSame([
            ['name' => 'ROLE_ADMIN', 'label' => 'Admin', 'bundle' => null, 'implies' => ['ROLE_USER']],
            ['name' => 'ROLE_USER', 'label' => 'User', 'bundle' => null, 'implies' => []],
            // Also what it brings indirectly
            ['name' => 'ROLE_ADMIN_BOOKS', 'label' => 'Admin books', 'bundle' => 'BooksBundle', 'implies' => ['ROLE_USER', 'ROLE_USER_BOOKS']],
            ['name' => 'ROLE_USER_BOOKS', 'label' => 'User books', 'bundle' => 'BooksBundle', 'implies' => ['ROLE_USER']],
        ], self::jsonResponse($client));
    }

    public function testCreatesAUserWhoChoosesThePasswordFromTheWelcomeEmail(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('POST', self::apiUrl('/users'), [
            'email' => '  New.User@Example.test ',
            'password' => null,
            'roles' => ['ROLE_USER_BOOKS', 'ROLE_USER', 'ROLE_USER_BOOKS'],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $json = self::jsonResponse($client);
        $this->assertSame([
            'id' => $json['id'],
            'email' => 'new.user@example.test',
            'roles' => ['ROLE_USER', 'ROLE_USER_BOOKS'],
            'effective_roles' => ['ROLE_USER', 'ROLE_USER_BOOKS'],
            'discord_linked' => false,
            'discord_username' => null,
            'telegram_linked' => false,
            'telegram_username' => null,
            'enabled' => true,
            'disabled_at' => null,
        ], $json);

        $user = $this->users->rows[$json['id']];
        $this->assertSame('new.user@example.test', $user->getEmail());
        $roles = $user->getRoles();
        sort($roles);
        $this->assertSame(['ROLE_USER', 'ROLE_USER_BOOKS'], $roles);
        // A random password nobody knows, hashed
        $this->assertStringStartsWith('$', $user->getPassword());
        $this->assertFalse(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, ''));

        // The welcome email, with a link that works for 7 days on the reset password page
        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'To', 'new.user@example.test');
        $this->assertEmailHeaderSame($email, 'Subject', 'Your account is ready');
        $this->assertEmailHtmlBodyContains($email, 'expires in 7 days');
        $this->assertEmailHtmlBodyNotContains($email, 'also gave your account a password');
        $this->assertMatchesRegularExpression(self::RESET_LINK, $email->getTextBody());
        preg_match(self::RESET_LINK, $email->getTextBody(), $matches);
        $this->assertStringStartsWith(self::url('games.domain_name', '/reset-password/'), $matches[0]);

        $request = $this->resetRequests->rowsOf($user)[0];
        $this->assertEqualsWithDelta(time() + WelcomeLinkSender::LINK_LIFETIME, $request->getExpiresAt()->getTimestamp(), 5);
        $this->assertSame($user, static::getContainer()->get(ResetPasswordHelperInterface::class)->validateTokenAndFetchUser($matches[1]));
    }

    public function testCreatesAUserWithThePasswordChosenByTheAdmin(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('POST', self::apiUrl('/users'), [
            'email' => 'new@example.test',
            'password' => self::PASSWORD,
            'roles' => [],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $json = self::jsonResponse($client);
        $this->assertSame([], $json['roles']);
        $this->assertArrayNotHasKey('password', $json);

        $user = $this->users->rows[$json['id']];
        $this->assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));

        // The link too, but never the password
        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailHtmlBodyContains($email, 'also gave your account a password');
        $this->assertEmailHtmlBodyNotContains($email, self::PASSWORD);
        $this->assertMatchesRegularExpression(self::RESET_LINK, $email->getTextBody());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> body and the property path of the violation
     */
    public static function invalidUsers(): iterable
    {
        $valid = ['email' => 'new@example.test', 'password' => null, 'roles' => ['ROLE_USER']];

        yield 'blank email' => [['email' => '  '] + $valid, 'email'];
        yield 'invalid email' => [['email' => 'not an email'] + $valid, 'email'];
        yield 'too long email' => [['email' => str_repeat('a', 170).'@example.test'] + $valid, 'email'];
        yield 'email of another user, in another case' => [['email' => 'Member@Example.test'] + $valid, 'email'];
        yield 'too short password' => [['password' => 'Sh0rt!'] + $valid, 'password'];
        yield 'weak password' => [['password' => 'aaaaaaaaaaaaaaaa'] + $valid, 'password'];
        yield 'empty password' => [['password' => ''] + $valid, 'password'];
        yield 'unknown role' => [['roles' => ['ROLE_USER', 'ROLE_NOPE']] + $valid, 'roles'];
        yield 'role in another case' => [['roles' => ['role_user']] + $valid, 'roles'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidUsers')]
    public function testAnInvalidUserIsRefusedNextToTheField(array $body, string $propertyPath): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('POST', self::apiUrl('/users'), $body);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame([$propertyPath], array_keys(self::violations($client)));
        $this->assertCount(2, $this->users->rows);
        $this->assertQueuedEmailCount(0);
        $this->assertSame([], $this->resetRequests->rows);
    }

    public function testTheMessageOfAnEmailAlreadyUsedIsShown(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('POST', self::apiUrl('/users'), ['email' => 'member@example.test', 'password' => null, 'roles' => []]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame(['email' => ['A user with this email already exists.']], self::violations($client));
    }

    public function testDisablesAndEnablesAUser(): void
    {
        $client = $this->createAdminClient();
        $this->resetRequests->persistResetPasswordRequest(
            $this->resetRequests->createResetPasswordRequest($this->member, new \DateTimeImmutable('+1 hour'), 'selector', 'hashed'),
        );

        $client->jsonRequest('PATCH', self::apiUrl('/users/7'), ['enabled' => false]);

        $this->assertResponseIsSuccessful();
        $json = self::jsonResponse($client);
        $this->assertFalse($json['enabled']);
        $this->assertNotNull($json['disabled_at']);
        $this->assertTrue($this->member->isDisabled());
        // Every remember-me login and pending password link of the user ends
        $this->assertSame([['member@example.test', false]], $this->rememberMeTokens->calls);
        $this->assertSame([], $this->resetRequests->rowsOf($this->member));

        $client->jsonRequest('PATCH', self::apiUrl('/users/7'), ['enabled' => true]);

        $this->assertResponseIsSuccessful();
        $this->assertSame([true, null], [self::jsonResponse($client)['enabled'], self::jsonResponse($client)['disabled_at']]);
        $this->assertFalse($this->member->isDisabled());
    }

    public function testAnAdminCannotDisableTheirOwnAccount(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('PATCH', self::apiUrl('/users/42'), ['enabled' => false]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame(['enabled' => ['You cannot disable your own account.']], self::violations($client));
        $this->assertFalse($this->admin->isDisabled());
        $this->assertSame([], $this->rememberMeTokens->calls);
    }

    public function testChangingAMissingUserAnswers404(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('PATCH', self::apiUrl('/users/999'), ['enabled' => false]);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->jsonRequest('PUT', self::apiUrl('/users/999/roles'), ['roles' => ['ROLE_USER']]);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array<string, UserRole> assigned roles of the user by name, to tell kept rows from new ones
     */
    private static function assignedRoles(User $user): array
    {
        $assigned = [];
        foreach ($user->getAssignedRoles() as $userRole) {
            $assigned[(string) $userRole->getRole()?->getName()] = $userRole;
        }
        ksort($assigned);

        return $assigned;
    }

    public function testReplacesTheRolesOfAUserKeepingTheUnchangedOnes(): void
    {
        $client = $this->createAdminClient();
        $this->member->addAssignedRole((new UserRole())->setRole($this->roles['ROLE_USER_BOOKS']));
        $kept = self::assignedRoles($this->member)['ROLE_USER_BOOKS'];

        $client->jsonRequest('PUT', self::apiUrl('/users/7/roles'), ['roles' => ['ROLE_USER_BOOKS', 'ROLE_ADMIN_BOOKS']]);

        $this->assertResponseIsSuccessful();
        $json = self::jsonResponse($client);
        $this->assertSame(['ROLE_ADMIN_BOOKS', 'ROLE_USER_BOOKS'], $json['roles']);
        // ROLE_USER is still there, inherited
        $this->assertSame(['ROLE_ADMIN_BOOKS', 'ROLE_USER', 'ROLE_USER_BOOKS'], $json['effective_roles']);
        $assigned = self::assignedRoles($this->member);
        $this->assertSame(['ROLE_ADMIN_BOOKS', 'ROLE_USER_BOOKS'], array_keys($assigned));
        // The row of a kept role stays: no delete and insert on the unique (user, role) index
        $this->assertSame($kept, $assigned['ROLE_USER_BOOKS']);
    }

    public function testAUserCanBeLeftWithoutRoles(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('PUT', self::apiUrl('/users/7/roles'), ['roles' => []]);

        $this->assertResponseIsSuccessful();
        $this->assertSame([[], []], [self::jsonResponse($client)['roles'], self::jsonResponse($client)['effective_roles']]);
        $this->assertSame([], $this->member->getRoles());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> body and the message on "roles"
     */
    public static function invalidRoleSets(): iterable
    {
        yield 'unknown role' => [['roles' => ['ROLE_USER', 'ROLE_NOPE']], 'Unknown role: ROLE_NOPE.'];
        // Never "remove every role" by mistake
        yield 'no roles key' => [[], 'Give the roles of the user, an empty list for none.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidRoleSets')]
    public function testAnInvalidRoleSetIsRefused(array $body, string $message): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('PUT', self::apiUrl('/users/7/roles'), $body);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame(['roles' => [$message]], self::violations($client));
        $this->assertSame(['ROLE_USER'], $this->member->getRoles());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function setsWithoutTheAdminRole(): iterable
    {
        yield 'bundle roles only' => [['ROLE_ADMIN_BOOKS', 'ROLE_USER']];
        yield 'no roles' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('setsWithoutTheAdminRole')]
    public function testAnAdminCannotRemoveTheAdminRoleFromThemselves(array $roles): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('PUT', self::apiUrl('/users/42/roles'), ['roles' => $roles]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame(['roles' => ['You cannot remove the admin role from yourself.']], self::violations($client));
        $this->assertSame(['ROLE_ADMIN', 'ROLE_ADMIN_BOOKS'], array_keys(self::assignedRoles($this->admin)));
        // Nothing changed, so the session goes on
        $client->request('GET', self::apiUrl('/users'));
        $this->assertResponseIsSuccessful();
    }

    public function testAnAdminCanChangeTheirOwnRolesKeepingTheAdminRoleAndIsLoggedOut(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('PUT', self::apiUrl('/users/42/roles'), ['roles' => ['ROLE_ADMIN']]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['ROLE_ADMIN'], self::jsonResponse($client)['roles']);
        // The session keeps the roles of the login: it ends at the next request
        $client->request('GET', self::apiUrl('/users'));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnAdminCanRemoveTheAdminRoleOfAnotherAdmin(): void
    {
        $client = $this->createAdminClient();
        $this->member->addAssignedRole((new UserRole())->setRole($this->roles['ROLE_ADMIN']));

        $client->jsonRequest('PUT', self::apiUrl('/users/7/roles'), ['roles' => ['ROLE_USER']]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['ROLE_USER'], $this->member->getRoles());
        // The admin making the change stays one: there is always an admin left
        $this->assertContains('ROLE_ADMIN', $this->admin->getRoles());
    }

    public function testTheEditedUserIsLoggedOutOnlyWhenTheRolesChange(): void
    {
        $client = $this->createClientWithUsers();
        $client->loginUser($this->member);
        $client->request('GET', self::url('games.domain_name', '/api/me'));
        $this->assertResponseIsSuccessful();
        $userManager = static::getContainer()->get(AdminUserManager::class);

        // Saved by an admin meanwhile, as the page does it, with the same roles: nothing changes
        $this->assertFalse($userManager->setRoles($this->member, ['ROLE_USER'], $this->admin));
        $client->request('GET', self::url('games.domain_name', '/api/me'));
        $this->assertResponseIsSuccessful();

        $this->assertTrue($userManager->setRoles($this->member, ['ROLE_USER', 'ROLE_USER_BOOKS'], $this->admin));
        $client->request('GET', self::url('games.domain_name', '/api/me'));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testADisabledUserIsLoggedOutAtTheNextRequest(): void
    {
        $client = $this->createClientWithUsers();
        $client->loginUser($this->member);
        $client->request('GET', self::url('games.domain_name', '/api/me'));
        $this->assertResponseIsSuccessful();

        // Disabled by an admin meanwhile: the firewall reloads this same instance
        $this->member->setDisabledAt(new \DateTimeImmutable());

        $client->request('GET', self::url('games.domain_name', '/api/me'));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $client->request('GET', self::url('games.domain_name', '/profile'));
        $this->assertResponseRedirects('/login');

        // The session is gone: enabling the account again does not log it back in
        $this->member->setDisabledAt(null);
        $client->request('GET', self::url('games.domain_name', '/api/me'));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testADisabledUserCannotLogInWithTheForm(): void
    {
        $client = $this->createClientWithUsers();

        $this->logInWithTheForm($client, 'member@example.test', self::PASSWORD);
        $this->assertResponseRedirects('/');
        $client->request('GET', self::url('domain_name', '/api/me'));
        $this->assertResponseIsSuccessful();
        $client->request('GET', self::url('domain_name', '/logout'));

        $this->member->setDisabledAt(new \DateTimeImmutable());
        $this->logInWithTheForm($client, 'member@example.test', self::PASSWORD);

        $this->assertResponseRedirects('/login');
        $client->followRedirect();
        $this->assertSame('Your account has been disabled.', self::loginProps($client)['error']);
        $client->request('GET', self::url('domain_name', '/api/me'));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // Only whoever knows the password learns that the account is disabled
        $this->logInWithTheForm($client, 'member@example.test', 'wrong password');
        $client->followRedirect();
        $this->assertSame('Invalid credentials.', self::loginProps($client)['error']);
    }

    public function testADisabledUserCannotLogInWithAnApiToken(): void
    {
        $client = $this->createClientWithUsers();
        $token = Token::createFor($this->member, 'Script');
        $this->tokens->save($token);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$token->getAccessToken()];

        $client->request('GET', self::url('games.domain_name', '/api/me'), [], [], $headers);
        $this->assertResponseIsSuccessful();

        $this->member->setDisabledAt(new \DateTimeImmutable());
        $client->request('GET', self::url('games.domain_name', '/api/me'), [], [], $headers);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Posts the login form as the browser does, from the same origin (stateless CSRF token).
     */
    private function logInWithTheForm(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', self::url('domain_name', '/login'));
        $props = self::loginProps($client);

        $client->request('POST', self::url('domain_name', $props['checkPath']), [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $props['csrfToken'],
        ], [], ['HTTP_ORIGIN' => 'https://'.static::getContainer()->getParameter('domain_name')]);
    }

    /**
     * @return array<string, mixed> the props of the React login page
     */
    private static function loginProps(KernelBrowser $client): array
    {
        return json_decode((string) $client->getCrawler()->filter('#react-login-app')->attr('data-props'), true);
    }
}

<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\Token;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Repository\TokenRepository;
use App\CoreBundle\Repository\UserRepository;
use App\Tests\CoreBundle\InMemoryTokenRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/me/tokens and the login with an API token, through the whole HTTP stack (firewall, access token
 * authenticator, ApiTokenVoter, MapRequestPayload), without a database: the tokens are kept in memory.
 */
class ApiMeTokensTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private InMemoryTokenRepository $tokens;
    private User $user;
    /** Tokens of the user: an active one and a disabled one made before descriptions existed */
    private Token $active;
    private Token $disabled;
    /** Token of another user */
    private Token $othersToken;

    protected function setUp(): void
    {
        $this->roles['ROLE_USER'] = self::role('ROLE_USER', 'User', null);
    }

    private function createClientWithTokens(): KernelBrowser
    {
        $client = $this->createClientWithoutDatabase();
        $this->user = $this->createUser(['ROLE_USER']);

        $this->tokens = new InMemoryTokenRepository();
        $this->active = Token::createFor($this->user, 'Home server');
        $this->disabled = Token::createFor($this->user, 'old')->setDescription(null)->setValid(false);
        $this->othersToken = Token::createFor((new User())->setEmail('other@example.test')->setPassword('x'), 'Not mine');
        foreach ([$this->active, $this->disabled, $this->othersToken] as $token) {
            $this->tokens->save($token);
        }
        static::getContainer()->set(TokenRepository::class, $this->tokens);

        // The firewall reloads the user of the session by id, and loads the one of an API token by email
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($this->user);
        $userRepository->method('findOneBy')
            ->willReturnCallback(fn (array $criteria): ?User => $criteria === ['email' => 'me@example.test'] ? $this->user : null);
        static::getContainer()->set(UserRepository::class, $userRepository);

        return $client;
    }

    private function createLoggedInClient(): KernelBrowser
    {
        $client = $this->createClientWithTokens();
        $client->loginUser($this->user);

        return $client;
    }

    private static function tokensUrl(string $path = ''): string
    {
        return self::url('games.domain_name', '/api/me/tokens'.$path);
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

    public function testListsTheTokensOfTheUserActiveAndDisabledWithoutTheirValues(): void
    {
        $client = $this->createLoggedInClient();

        $client->request('GET', self::tokensUrl());

        $this->assertResponseIsSuccessful();
        $this->assertSame([
            ['id' => $this->active->getId(), 'description' => 'Home server', 'valid' => true, 'token' => null],
            ['id' => $this->disabled->getId(), 'description' => null, 'valid' => false, 'token' => null],
        ], self::jsonResponse($client));
    }

    public function testCreatesAnActiveTokenAndGivesItsValue(): void
    {
        $client = $this->createLoggedInClient();

        $client->jsonRequest('POST', self::tokensUrl(), ['description' => '  Phone shortcut  ']);

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $json = self::jsonResponse($client);
        $this->assertSame('Phone shortcut', $json['description']);
        $this->assertTrue($json['valid']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $json['token']);

        $stored = $this->tokens->rows[$json['id']];
        $this->assertSame('me@example.test', $stored->getUsername());
        $this->assertSame(User::class, $stored->getClass());
        $this->assertSame(hash('sha256', $json['token']), $stored->getTokenHash());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDescriptions(): iterable
    {
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('a', 256)];
    }

    #[DataProvider('invalidDescriptions')]
    public function testAnInvalidDescriptionIsRefused(string $description): void
    {
        $client = $this->createLoggedInClient();

        $client->jsonRequest('POST', self::tokensUrl(), ['description' => $description]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertArrayHasKey('description', self::violations($client));

        $client->jsonRequest('PATCH', self::tokensUrl('/'.$this->active->getId()), ['description' => $description]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame('Home server', $this->active->getDescription());
        $this->assertCount(3, $this->tokens->rows);
    }

    public function testShowsTheValueOfATokenOfTheUser(): void
    {
        $client = $this->createLoggedInClient();

        $client->request('GET', self::tokensUrl('/'.$this->disabled->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['id' => $this->disabled->getId(), 'description' => null, 'valid' => false, 'token' => $this->disabled->getAccessToken()],
            self::jsonResponse($client),
        );
    }

    public function testDisablesEnablesAndRenamesATokenOfTheUser(): void
    {
        $client = $this->createLoggedInClient();
        $url = self::tokensUrl('/'.$this->active->getId());

        $client->jsonRequest('PATCH', $url, ['valid' => false]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['id' => $this->active->getId(), 'description' => 'Home server', 'valid' => false, 'token' => null], self::jsonResponse($client));
        $this->assertFalse($this->active->isValid());

        $client->jsonRequest('PATCH', $url, ['valid' => true, 'description' => ' Home server (NAS) ']);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->active->isValid());
        $this->assertSame('Home server (NAS)', $this->active->getDescription());

        // Nothing to change
        $client->jsonRequest('PATCH', $url, []);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Home server (NAS)', self::jsonResponse($client)['description']);
    }

    public function testDeletesATokenOfTheUser(): void
    {
        $client = $this->createLoggedInClient();

        $client->request('DELETE', self::tokensUrl('/'.$this->active->getId()));

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->assertArrayNotHasKey($this->active->getId(), $this->tokens->rows);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function requestsOnAToken(): iterable
    {
        yield 'show' => ['GET', '', null];
        yield 'update' => ['PATCH', '', ['valid' => false]];
        yield 'delete' => ['DELETE', '', null];
    }

    #[DataProvider('requestsOnAToken')]
    public function testATokenOfAnotherUserIsNotFound(string $method, string $suffix, ?array $body): void
    {
        $client = $this->createLoggedInClient();
        $url = self::tokensUrl('/'.$this->othersToken->getId().$suffix);

        $body === null ? $client->request($method, $url) : $client->jsonRequest($method, $url, $body);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->assertTrue($this->othersToken->isValid());
        $this->assertArrayHasKey($this->othersToken->getId(), $this->tokens->rows);
    }

    public function testNeedsALoggedInUser(): void
    {
        $client = $this->createClientWithTokens();

        $client->request('GET', self::tokensUrl());

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testNeedsRoleUser(): void
    {
        $client = $this->createClientWithTokens();
        $this->user->getAssignedRoles()->clear();
        $client->loginUser($this->user);

        $client->request('GET', self::tokensUrl());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnActiveTokenLogsIn(): void
    {
        $client = $this->createClientWithTokens();

        $client->request('GET', self::url('games.domain_name', '/api/me'), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->active->getAccessToken()]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('me@example.test', self::jsonResponse($client)['email']);
    }

    /**
     * @return iterable<string, array{\Closure(self): string}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'disabled' => [static fn (self $test): string => $test->disabled->getAccessToken()];
        yield 'unknown' => [static fn (self $test): string => str_repeat('0', 64)];
        // The value is never compared: only its hash finds a token
        yield 'the hash of an active one' => [static fn (self $test): string => $test->active->getTokenHash()];
    }

    #[DataProvider('refusedTokens')]
    public function testOtherTokensDoNotLogIn(\Closure $value): void
    {
        $client = $this->createClientWithTokens();

        $client->request('GET', self::url('games.domain_name', '/api/me'), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$value($this)]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheTokensCannotBeManagedWithAnApiToken(): void
    {
        $client = $this->createClientWithTokens();
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->active->getAccessToken()];

        $client->request('GET', self::tokensUrl(), server: $bearer);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->jsonRequest('POST', self::tokensUrl(), ['description' => 'Minted with a token'], $bearer);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertCount(3, $this->tokens->rows);

        $client->request('GET', self::tokensUrl('/'.$this->disabled->getId()), server: $bearer);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertSame('Manage the API tokens from the profile page, not with an API token.', self::jsonResponse($client)['detail']);
    }

    public function testAnApiTokenLoginIsNeverKeptInTheSession(): void
    {
        $client = $this->createClientWithTokens();

        $client->request('GET', self::url('games.domain_name', '/api/me'), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->active->getAccessToken()]);
        $this->assertResponseIsSuccessful();
        $this->assertSame([], $client->getCookieJar()->all());

        // Without the header the request is anonymous
        $client->request('GET', self::url('games.domain_name', '/api/me'));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @return iterable<string, array{\Closure(self): void}>
     */
    public static function revocations(): iterable
    {
        yield 'disabled' => [static fn (self $test) => $test->active->setValid(false)];
        yield 'deleted' => [static function (self $test): void {
            unset($test->tokens->rows[$test->active->getId()]);
        }];
    }

    #[DataProvider('revocations')]
    public function testADisabledOrDeletedTokenStopsAtOnce(\Closure $revoke): void
    {
        $client = $this->createClientWithTokens();
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->active->getAccessToken()];
        $client->request('GET', self::url('games.domain_name', '/api/me'), server: $bearer);
        $this->assertResponseIsSuccessful();

        $revoke($this);

        $client->request('GET', self::url('games.domain_name', '/api/me'), server: $bearer);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $client->request('GET', self::url('games.domain_name', '/api/me'));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnApiTokenFromABrowserLeavesItsSessionAlone(): void
    {
        $client = $this->createLoggedInClient();

        $client->request('GET', self::tokensUrl(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->active->getAccessToken()]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // The session of the browser is still the user's own login
        $client->request('GET', self::tokensUrl());
        $this->assertResponseIsSuccessful();
    }

    public function testAnApiTokenWithoutTheRightsGetsJsonNotARedirect(): void
    {
        $client = $this->createClientWithTokens();
        $this->user->getAssignedRoles()->clear();

        // A page, not an API route: the stateless firewall has no session for the flash of the redirect to the login
        $client->request('GET', self::url('games.domain_name', '/profile'), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->active->getAccessToken()]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }
}

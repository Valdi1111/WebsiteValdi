<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Discord\DiscordUser;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Service\Discord\DiscordOAuthClient;
use App\Tests\CoreBundle\FakeDiscordApi;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Link Discord" (/profile/discord/connect and /profile/discord/callback) and /api/me/discord, through the whole
 * HTTP stack (firewall, IsGranted, session shared between the subdomains), without a database. Discord is
 * FakeDiscordApi behind the real DiscordOAuthClient.
 */
class DiscordLinkTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private FakeDiscordApi $discord;
    private User $user;
    /** Discord id that UserRepository::findOneBy() finds on another user */
    private ?string $idOfAnotherUser = null;

    protected function setUp(): void
    {
        $this->roles['ROLE_USER'] = self::role('ROLE_USER', 'User', null);
        $this->discord = new FakeDiscordApi();
    }

    private function createDiscordClient(bool $configured = true, bool $logIn = true): KernelBrowser
    {
        $client = $this->createClientWithoutDatabase();
        $container = static::getContainer();

        // DEFAULT_URI of the test environment: the main domain
        $container->set(DiscordOAuthClient::class, new DiscordOAuthClient(
            $this->discord->client(),
            $container->get(UrlGeneratorInterface::class),
            $configured ? 'client-id' : '',
            $configured ? 'client-secret' : '',
            'https://'.$container->getParameter('domain_name'),
        ));

        $this->user = $this->createUser(['ROLE_USER'])->setDiscordUserId(null);
        // The firewall reloads the user of the session by id; DiscordAccountLinker looks for the Discord id
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($this->user);
        $userRepository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?User => match ($criteria['discordUserId'] ?? null) {
            null => null,
            $this->idOfAnotherUser => (new User())->setEmail('other@example.test')->setPassword('x')->setDiscordUserId($this->idOfAnotherUser),
            $this->user->getDiscordUserId() => $this->user,
            default => null,
        });
        $container->set(UserRepository::class, $userRepository);

        if ($logIn) {
            $client->loginUser($this->user);
        }

        return $client;
    }

    /**
     * Starts the flow on a subdomain and answers with the state sent to Discord.
     */
    private function connect(KernelBrowser $client, string $hostParameter = 'games.domain_name'): string
    {
        $client->request('GET', self::url($hostParameter, '/profile/discord/connect'));

        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(DiscordOAuthClient::AUTHORIZE_URL.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    /**
     * Discord sends the browser back to the callback, always on the main domain.
     *
     * @param array<string, string> $query
     */
    private function discordCallback(KernelBrowser $client, array $query): void
    {
        $client->request('GET', self::url('domain_name', '/profile/discord/callback?'.http_build_query($query)));
    }

    private function assertLinkedTo(DiscordUser $expected): void
    {
        $this->assertSame(
            [$expected->id, $expected->username, $expected->globalName, $expected->avatar],
            [$this->user->getDiscordUserId(), $this->user->getDiscordUsername(), $this->user->getDiscordGlobalName(), $this->user->getDiscordAvatar()],
        );
    }

    public function testConnectRedirectsToDiscordWithTheCallbackOnTheMainDomain(): void
    {
        $client = $this->createDiscordClient();

        $client->request('GET', self::url('books.domain_name', '/profile/discord/connect'));

        $this->assertResponseRedirects();
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('identify', $query['scope']);
        $this->assertSame(self::url('domain_name', '/profile/discord/callback'), $query['redirect_uri']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $query['state']);
        // Nothing asked to Discord yet: the user is still on the way to its authorization page
        $this->assertSame([], $this->discord->requests);
    }

    public function testLinkingFromASubdomainEndsOnTheProfilePageOfThatSubdomain(): void
    {
        $client = $this->createDiscordClient();
        $state = $this->connect($client, 'games.domain_name');

        $this->discordCallback($client, ['code' => 'the-code', 'state' => $state, 'iss' => 'https://discord.com']);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?discord=linked'));
        $this->assertLinkedTo(new DiscordUser(FakeDiscordApi::USER_ID, 'nelly', 'Nelly', '8342729096ea3675442027381ff50dfe'));
        $this->assertSame(['POST oauth2/token', 'GET users/@me', 'POST oauth2/token/revoke'], $this->discord->requestedPaths());
        $this->assertSame(self::url('domain_name', '/profile/discord/callback'), $this->discord->formBody(0)['redirect_uri']);
    }

    public function testCallbackWithAnotherStateIsRefusedWithoutAskingDiscord(): void
    {
        $client = $this->createDiscordClient();
        $this->connect($client);

        $this->discordCallback($client, ['code' => 'the-code', 'state' => 'forged']);

        // The subdomain of the flow is not trusted without the state: back to the profile of the current host
        $this->assertResponseRedirects('/profile?discord=error');
        $this->assertSame([], $this->discord->requests);
        $this->assertNull($this->user->getDiscordUserId());
    }

    public function testCallbackWithoutAFlowStartedInTheSessionIsRefused(): void
    {
        $client = $this->createDiscordClient();

        $this->discordCallback($client, ['code' => 'the-code', 'state' => 'whatever']);

        $this->assertResponseRedirects('/profile?discord=error');
        $this->assertSame([], $this->discord->requests);
    }

    public function testAStateIsGoodForOneCallback(): void
    {
        $client = $this->createDiscordClient();
        $state = $this->connect($client);
        $this->discordCallback($client, ['code' => 'the-code', 'state' => $state]);
        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?discord=linked'));

        $this->discordCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects('/profile?discord=error');
        $this->assertCount(3, $this->discord->requests);
    }

    public function testCancellingOnDiscordGoesBackWithoutLinking(): void
    {
        $client = $this->createDiscordClient();
        $state = $this->connect($client, 'anime.domain_name');

        $this->discordCallback($client, ['error' => 'access_denied', 'error_description' => 'The resource owner or authorization server denied the request', 'state' => $state]);

        $this->assertResponseRedirects(self::url('anime.domain_name', '/profile?discord=cancelled'));
        $this->assertSame([], $this->discord->requests);
        $this->assertNull($this->user->getDiscordUserId());
    }

    public function testAnAccountLinkedToAnotherUserIsRefused(): void
    {
        $this->idOfAnotherUser = FakeDiscordApi::USER_ID;
        $client = $this->createDiscordClient();
        $state = $this->connect($client);

        $this->discordCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?discord=already_linked'));
        $this->assertNull($this->user->getDiscordUserId());
        // The user was read, so the token is revoked all the same
        $this->assertSame('POST oauth2/token/revoke', $this->discord->requestedPaths()[2] ?? null);
    }

    public function testLinkingTheSameAccountAgainRefreshesNamesAndAvatar(): void
    {
        $client = $this->createDiscordClient();
        $this->user->linkDiscordAccount(new DiscordUser(FakeDiscordApi::USER_ID, 'old_name', null, 'old-hash'));
        $state = $this->connect($client);

        $this->discordCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?discord=linked'));
        $this->assertLinkedTo(new DiscordUser(FakeDiscordApi::USER_ID, 'nelly', 'Nelly', '8342729096ea3675442027381ff50dfe'));
    }

    public function testADiscordErrorGoesBackWithAnError(): void
    {
        $this->discord->tokenStatus = 400;
        $client = $this->createDiscordClient();
        $state = $this->connect($client);

        $this->discordCallback($client, ['code' => 'expired-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?discord=error'));
        $this->assertNull($this->user->getDiscordUserId());
    }

    public function testConnectWithoutTheDiscordApplicationGoesBackAsUnavailable(): void
    {
        $client = $this->createDiscordClient(configured: false);

        $client->request('GET', self::url('games.domain_name', '/profile/discord/connect'));

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?discord=unavailable'));
    }

    public function testAnotherHostIsNeverTheOneToGoBackTo(): void
    {
        $client = $this->createDiscordClient();
        $client->request('GET', 'https://evil.example/profile/discord/connect');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->discordCallback($client, ['code' => 'the-code', 'state' => $query['state']]);

        $this->assertResponseRedirects('/profile?discord=linked');
    }

    public function testConnectRedirectsToLoginWhenNotLoggedIn(): void
    {
        $client = $this->createDiscordClient(logIn: false);

        $client->request('GET', self::url('games.domain_name', '/profile/discord/connect'));

        $this->assertResponseRedirects('/login');
    }

    public function testApiMeDiscordGivesTheLinkedAccount(): void
    {
        $client = $this->createDiscordClient();
        $this->user->linkDiscordAccount(new DiscordUser(FakeDiscordApi::USER_ID, 'nelly', 'Nelly', '8342729096ea3675442027381ff50dfe'));

        $client->request('GET', self::url('games.domain_name', '/api/me/discord'));

        $this->assertResponseIsSuccessful();
        $this->assertSame([
            'linked' => true,
            'username' => 'nelly',
            'global_name' => 'Nelly',
            'avatar_url' => 'https://cdn.discordapp.com/avatars/80351110224678912/8342729096ea3675442027381ff50dfe.webp?size=128',
        ], self::jsonResponse($client));
    }

    public function testApiMeDiscordWithAnIdSetByHandHasNoNamesButTheDefaultAvatar(): void
    {
        $client = $this->createDiscordClient();
        $this->user->setDiscordUserId(FakeDiscordApi::USER_ID);

        $client->request('GET', self::url('games.domain_name', '/api/me/discord'));

        $this->assertSame(
            ['linked' => true, 'username' => null, 'global_name' => null, 'avatar_url' => 'https://cdn.discordapp.com/embed/avatars/5.png'],
            self::jsonResponse($client),
        );
    }

    public function testApiMeDiscordWithoutALinkedAccount(): void
    {
        $client = $this->createDiscordClient();

        $client->request('GET', self::url('games.domain_name', '/api/me/discord'));

        $this->assertSame(['linked' => false, 'username' => null, 'global_name' => null, 'avatar_url' => null], self::jsonResponse($client));
    }

    public function testDeleteApiMeDiscordUnlinksTheAccount(): void
    {
        $client = $this->createDiscordClient();
        $this->user->linkDiscordAccount(new DiscordUser(FakeDiscordApi::USER_ID, 'nelly', 'Nelly', 'hash'));

        $client->request('DELETE', self::url('games.domain_name', '/api/me/discord'));

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->assertSame([null, null, null, null], [
            $this->user->getDiscordUserId(),
            $this->user->getDiscordUsername(),
            $this->user->getDiscordGlobalName(),
            $this->user->getDiscordAvatar(),
        ]);
    }
}

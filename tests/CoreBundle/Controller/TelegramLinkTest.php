<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Telegram\TelegramUser;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Service\Telegram\TelegramAccountLinker;
use App\CoreBundle\Service\Telegram\TelegramOAuthClient;
use App\Tests\CoreBundle\FakeTelegramApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Link Telegram" (/profile/telegram/connect and /profile/telegram/callback) and /api/me/telegram, through the whole
 * HTTP stack (firewall, IsGranted, session shared between the subdomains), without a database. Telegram is
 * FakeTelegramApi behind the real TelegramOAuthClient and TelegramBot.
 */
class TelegramLinkTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private const string TOKEN_URL = 'POST https://oauth.telegram.org/token';
    private const string KEYS_URL = 'GET https://oauth.telegram.org/.well-known/jwks.json';
    private const string SEND_URL = 'POST https://api.telegram.org/bot'.FakeTelegramApi::BOT_TOKEN.'/sendMessage';

    private FakeTelegramApi $telegram;
    private User $user;
    /** Telegram id that UserRepository::findOneBy() finds on another user */
    private ?string $idOfAnotherUser = null;

    protected function setUp(): void
    {
        $this->roles['ROLE_USER'] = self::role('ROLE_USER', 'User', null);
        $this->telegram = new FakeTelegramApi(new MockClock());
    }

    private function createTelegramClient(bool $configured = true, bool $logIn = true, ?string $botDsn = null): KernelBrowser
    {
        $client = $this->createClientWithoutDatabase();
        $container = static::getContainer();

        // DEFAULT_URI of the test environment: the main domain
        $container->set(TelegramOAuthClient::class, $this->telegram->oauthClient(
            $container->get(UrlGeneratorInterface::class),
            'https://'.$container->getParameter('domain_name'),
            clientId: $configured ? FakeTelegramApi::BOT_ID : '',
            botDsn: $botDsn ?? 'telegram://'.FakeTelegramApi::BOT_TOKEN.'@default',
        ));

        $this->user = $this->createUser(['ROLE_USER'])->unlinkTelegramAccount();
        // The firewall reloads the user of the session by id; TelegramAccountLinker looks for the Telegram id
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($this->user);
        $userRepository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?User => match ($criteria['telegramChatId'] ?? null) {
            null => null,
            $this->idOfAnotherUser => (new User())->setEmail('other@example.test')->setPassword('x')->setTelegramChatId((string) $this->idOfAnotherUser),
            $this->user->getTelegramChatId() => $this->user,
            default => null,
        });
        $container->set(UserRepository::class, $userRepository);
        // The bot of the fake: replaced through the linker, its only user
        $container->set(TelegramAccountLinker::class, new TelegramAccountLinker(
            $userRepository,
            $container->get(EntityManagerInterface::class),
            $this->telegram->bot(),
            $container->getParameter('domain_name'),
        ));

        if ($logIn) {
            $client->loginUser($this->user);
        }

        return $client;
    }

    /**
     * Starts the flow on a subdomain and answers with the query of the authorization request sent to Telegram.
     *
     * @return array<string, string>
     */
    private function connect(KernelBrowser $client, string $hostParameter = 'games.domain_name'): array
    {
        $client->request('GET', self::url($hostParameter, '/profile/telegram/connect'));

        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(TelegramOAuthClient::AUTHORIZE_URL.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * Telegram sends the browser back to the callback, always on the main domain.
     *
     * @param array<string, string> $query
     */
    private function telegramCallback(KernelBrowser $client, array $query): void
    {
        $client->request('GET', self::url('domain_name', '/profile/telegram/callback?'.http_build_query($query)));
    }

    private function assertLinkedTo(?string $id, ?string $username = null, ?string $name = null): void
    {
        $this->assertSame(
            [$id, $username, $name],
            [$this->user->getTelegramChatId(), $this->user->getTelegramUsername(), $this->user->getTelegramName()],
        );
    }

    public function testConnectRedirectsToTelegramWithTheCallbackOnTheMainDomain(): void
    {
        $client = $this->createTelegramClient();

        $query = $this->connect($client, 'books.domain_name');

        $this->assertSame(FakeTelegramApi::BOT_ID, $query['client_id']);
        $this->assertSame('openid profile telegram:bot_access', $query['scope']);
        $this->assertSame(self::url('domain_name', '/profile/telegram/callback'), $query['redirect_uri']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        // Nothing asked to Telegram yet: the user is still on the way to its consent page
        $this->assertSame([], $this->telegram->requests);
    }

    public function testLinkingFromASubdomainSendsTheWelcomeAndEndsOnTheProfilePageOfThatSubdomain(): void
    {
        $client = $this->createTelegramClient();
        $authorization = $this->connect($client, 'games.domain_name');

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => $authorization['state']]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=linked'));
        $this->assertLinkedTo(FakeTelegramApi::USER_ID, 'nelly', 'Nelly');
        $this->assertSame([self::TOKEN_URL, self::KEYS_URL, self::SEND_URL], $this->telegram->requestedUrls());
        $exchange = $this->telegram->body(0);
        $this->assertSame(self::url('domain_name', '/profile/telegram/callback'), $exchange['redirect_uri']);
        // PKCE: the verifier kept in the session matches the challenge sent with the authorization request
        $this->assertSame($authorization['code_challenge'], FakeTelegramApi::base64Url(hash('sha256', $exchange['code_verifier'], true)));
        $welcome = $this->telegram->body(2);
        $this->assertSame(FakeTelegramApi::USER_ID, $welcome['chat_id']);
        // Escaped by the transport for MarkdownV2, which Telegram would refuse otherwise ("." is reserved)
        $this->assertSame('MarkdownV2', $welcome['parse_mode']);
        $this->assertStringContainsString(str_replace('.', '\.', (string) static::getContainer()->getParameter('domain_name')), $welcome['text']);
    }

    public function testCallbackWithAnotherStateIsRefusedWithoutAskingTelegram(): void
    {
        $client = $this->createTelegramClient();
        $this->connect($client);

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => 'forged']);

        // The subdomain of the flow is not trusted without the state: back to the profile of the current host
        $this->assertResponseRedirects('/profile?telegram=error');
        $this->assertSame([], $this->telegram->requests);
        $this->assertLinkedTo(null);
    }

    public function testCallbackWithoutAFlowStartedInTheSessionIsRefused(): void
    {
        $client = $this->createTelegramClient();

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => 'whatever']);

        $this->assertResponseRedirects('/profile?telegram=error');
        $this->assertSame([], $this->telegram->requests);
    }

    public function testAStateIsGoodForOneCallback(): void
    {
        $client = $this->createTelegramClient();
        $state = $this->connect($client)['state'];
        $this->telegramCallback($client, ['code' => 'the-code', 'state' => $state]);
        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=linked'));

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects('/profile?telegram=error');
        $this->assertCount(3, $this->telegram->requests);
    }

    public function testCancellingOnTelegramGoesBackWithoutLinking(): void
    {
        $client = $this->createTelegramClient();
        $state = $this->connect($client, 'anime.domain_name')['state'];

        $this->telegramCallback($client, ['error' => 'access_denied', 'state' => $state]);

        $this->assertResponseRedirects(self::url('anime.domain_name', '/profile?telegram=cancelled'));
        $this->assertSame([], $this->telegram->requests);
        $this->assertLinkedTo(null);
    }

    public function testAnotherTelegramErrorGoesBackWithAnError(): void
    {
        $client = $this->createTelegramClient();
        $state = $this->connect($client)['state'];

        $this->telegramCallback($client, ['error' => 'invalid_scope', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=error'));
        $this->assertSame([], $this->telegram->requests);
    }

    public function testAnAccountLinkedToAnotherUserIsRefusedWithoutMessagingIt(): void
    {
        $this->idOfAnotherUser = FakeTelegramApi::USER_ID;
        $client = $this->createTelegramClient();
        $state = $this->connect($client)['state'];

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=already_linked'));
        $this->assertLinkedTo(null);
        $this->assertSame([self::TOKEN_URL, self::KEYS_URL], $this->telegram->requestedUrls());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function refusedMessages(): iterable
    {
        // "Allow Messages" off on the consent screen, as observed with the real Telegram
        yield 'messages not allowed at login' => [400, 'Bad Request: chat not found'];
        yield 'bot blocked' => [403, 'Forbidden: bot was blocked by the user'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedMessages')]
    public function testAnAccountTheBotCannotMessageIsNotLinked(int $status, string $description): void
    {
        $this->telegram->sendStatus = $status;
        $this->telegram->sendError = $description;
        $client = $this->createTelegramClient();
        $state = $this->connect($client)['state'];

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=messages_not_allowed'));
        $this->assertLinkedTo(null);
    }

    public function testAWelcomeMessageTelegramFailsToSendGoesBackWithAnError(): void
    {
        $this->telegram->sendStatus = 500;
        $client = $this->createTelegramClient();
        $state = $this->connect($client)['state'];

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=error'));
        $this->assertLinkedTo(null);
    }

    public function testAnExpiredCodeGoesBackWithAnError(): void
    {
        $this->telegram->tokenError = 'invalid_grant';
        $client = $this->createTelegramClient();
        $state = $this->connect($client)['state'];

        $this->telegramCallback($client, ['code' => 'expired-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=error'));
        $this->assertLinkedTo(null);
        $this->assertSame([self::TOKEN_URL], $this->telegram->requestedUrls());
    }

    public function testAnInvalidIdTokenGoesBackWithAnErrorWithoutMessaging(): void
    {
        $this->telegram->claims = ['aud' => '111111111'];
        $client = $this->createTelegramClient();
        $state = $this->connect($client)['state'];

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=error'));
        $this->assertLinkedTo(null);
        $this->assertNotContains(self::SEND_URL, $this->telegram->requestedUrls());
    }

    public function testLinkingTheSameAccountAgainRefreshesTheNames(): void
    {
        $client = $this->createTelegramClient();
        $this->user->linkTelegramAccount(new TelegramUser(FakeTelegramApi::USER_ID, 'old_name', 'Old'));
        $state = $this->connect($client)['state'];

        $this->telegramCallback($client, ['code' => 'the-code', 'state' => $state]);

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=linked'));
        $this->assertLinkedTo(FakeTelegramApi::USER_ID, 'nelly', 'Nelly');
    }

    public function testConnectWithoutTheBotConfiguredGoesBackAsUnavailable(): void
    {
        $client = $this->createTelegramClient(configured: false);

        $client->request('GET', self::url('games.domain_name', '/profile/telegram/connect'));

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=unavailable'));
    }

    public function testConnectWhenTheNotificationsUseAnotherBotGoesBackAsUnavailable(): void
    {
        $client = $this->createTelegramClient(botDsn: 'telegram://111111111:other@default');

        $client->request('GET', self::url('games.domain_name', '/profile/telegram/connect'));

        $this->assertResponseRedirects(self::url('games.domain_name', '/profile?telegram=unavailable'));
    }

    public function testConnectRedirectsToLoginWhenNotLoggedIn(): void
    {
        $client = $this->createTelegramClient(logIn: false);

        $client->request('GET', self::url('games.domain_name', '/profile/telegram/connect'));

        $this->assertResponseRedirects('/login');
    }

    public function testApiMeTelegramGivesTheLinkedAccount(): void
    {
        $client = $this->createTelegramClient();
        $this->user->linkTelegramAccount(new TelegramUser(FakeTelegramApi::USER_ID, 'nelly', 'Nelly'));

        $client->request('GET', self::url('games.domain_name', '/api/me/telegram'));

        $this->assertResponseIsSuccessful();
        $this->assertSame(['linked' => true, 'username' => 'nelly', 'name' => 'Nelly'], self::jsonResponse($client));
    }

    public function testApiMeTelegramWithAnIdSetByHandHasNoNames(): void
    {
        $client = $this->createTelegramClient();
        $this->user->setTelegramChatId(FakeTelegramApi::USER_ID);

        $client->request('GET', self::url('games.domain_name', '/api/me/telegram'));

        $this->assertSame(['linked' => true, 'username' => null, 'name' => null], self::jsonResponse($client));
    }

    public function testApiMeTelegramWithoutALinkedAccount(): void
    {
        $client = $this->createTelegramClient();

        $client->request('GET', self::url('games.domain_name', '/api/me/telegram'));

        $this->assertSame(['linked' => false, 'username' => null, 'name' => null], self::jsonResponse($client));
    }

    public function testDeleteApiMeTelegramUnlinksTheAccount(): void
    {
        $client = $this->createTelegramClient();
        $this->user->linkTelegramAccount(new TelegramUser(FakeTelegramApi::USER_ID, 'nelly', 'Nelly'));

        $client->request('DELETE', self::url('games.domain_name', '/api/me/telegram'));

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->assertLinkedTo(null);
    }
}

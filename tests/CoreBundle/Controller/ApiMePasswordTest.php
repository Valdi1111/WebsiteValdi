<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\ResetPasswordRequest;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Repository\ResetPasswordRequestRepository;
use App\CoreBundle\Security\RememberMeTokenRevoker;
use App\Tests\CoreBundle\InMemoryResetPasswordRequestRepository;
use App\Tests\CoreBundle\RecordingRememberMeTokenRevoker;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * POST /api/me/password through the whole HTTP stack (firewall, MapRequestPayload, rate limiter), without a
 * database: the user is refreshed from a stub repository, the reset links are kept in memory and the remember-me
 * logins (a table) are only recorded; the emails stay in the in-memory core_async transport.
 */
class ApiMePasswordTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private const string CURRENT_PASSWORD = 'old Horse battery 7 staple';
    private const string NEW_PASSWORD = 'Correct-Horse-Battery-Staple-42';

    private InMemoryResetPasswordRequestRepository $resetRequests;
    private RecordingRememberMeTokenRevoker $rememberMeTokens;

    protected function setUp(): void
    {
        $this->roles['ROLE_USER'] = self::role('ROLE_USER', 'User', null);
    }

    /**
     * @return array{KernelBrowser, User} a client logged in as a user whose password is CURRENT_PASSWORD
     */
    private function createLoggedInClient(): array
    {
        $client = $this->createClientWithoutDatabase();
        $container = static::getContainer();

        $this->resetRequests = new InMemoryResetPasswordRequestRepository();
        $container->set(ResetPasswordRequestRepository::class, $this->resetRequests);
        $this->rememberMeTokens = new RecordingRememberMeTokenRevoker();
        $container->set(RememberMeTokenRevoker::class, $this->rememberMeTokens);
        // No attempts counted by another test or run
        $container->get('cache.rate_limiter')->clear();

        $user = $this->createUser(['ROLE_USER']);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::CURRENT_PASSWORD));
        $this->logIn($client, $user);

        return [$client, $user];
    }

    private function changePassword(KernelBrowser $client, string $currentPassword, string $newPassword): void
    {
        $client->jsonRequest('POST', self::url('books.domain_name', '/api/me/password'), [
            'current_password' => $currentPassword,
            'new_password' => $newPassword,
        ]);
    }

    private static function isPassword(User $user, string $password): bool
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $password);
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

    public function testChangesThePasswordAndClosesTheOtherWaysToStayLoggedIn(): void
    {
        [$client, $user] = $this->createLoggedInClient();
        $otherUser = (new User())->setEmail('other@example.test')->setPassword('x');
        // A pending reset link of the user, and one of somebody else
        $this->resetRequests->rows = [
            new ResetPasswordRequest($user, new \DateTimeImmutable('+1 hour'), 'selectorOfTheUser000', 'hash'),
            new ResetPasswordRequest($otherUser, new \DateTimeImmutable('+1 hour'), 'selectorOfTheOther00', 'hash'),
        ];

        $this->changePassword($client, self::CURRENT_PASSWORD, self::NEW_PASSWORD);

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->assertTrue(self::isPassword($user, self::NEW_PASSWORD));
        $this->assertFalse(self::isPassword($user, self::CURRENT_PASSWORD));
        // The reset links of the user no longer work
        $this->assertSame([], $this->resetRequests->rowsOf($user));
        $this->assertCount(1, $this->resetRequests->rowsOf($otherUser));
        // Remember-me logins deleted, this device remembered again
        $this->assertSame([['me@example.test', true]], $this->rememberMeTokens->calls);

        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertInstanceOf(TemplatedEmail::class, $email);
        $this->assertEmailAddressContains($email, 'To', 'me@example.test');
        // The default sender (config/packages/mailer.yaml)
        $this->assertEmailAddressContains($email, 'From', 'noreply@'.static::getContainer()->getParameter('domain_name'));
        $this->assertEmailHeaderSame($email, 'Subject', 'Your password has been changed');
        // A link to the "forgot password" page of the host the user is on, also in the text part
        $this->assertEmailHtmlBodyContains($email, 'href="'.self::url('books.domain_name', '/forgot-password').'"');
        $this->assertEmailTextBodyContains($email, self::url('books.domain_name', '/forgot-password'));
        // The shared layout, with its CSS inlined for the clients that ignore <style>
        $this->assertEmailHtmlBodyContains($email, 'style="display: inline-block; padding: 10px 20px; border-radius: 6px; background-color: #1677ff;');
        // The text part, converted by league/html-to-markdown
        $this->assertEmailTextBodyContains($email, '[Reset your password]('.self::url('books.domain_name', '/forgot-password').')');
    }

    public function testTheCurrentSessionStaysLoggedInAndTheOtherSessionsAreLoggedOut(): void
    {
        [$client, $user] = $this->createLoggedInClient();
        // A session opened elsewhere before the change: its cookie is set aside, and the change is made from a new one
        $otherSessionCookie = $client->getCookieJar()->all();
        $client->getCookieJar()->clear();
        $client->loginUser($user);

        $this->changePassword($client, self::CURRENT_PASSWORD, self::NEW_PASSWORD);
        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $client->request('GET', self::url('books.domain_name', '/api/me'));
        $this->assertResponseIsSuccessful();

        $client->getCookieJar()->clear();
        foreach ($otherSessionCookie as $cookie) {
            $client->getCookieJar()->set($cookie);
        }
        $client->request('GET', self::url('books.domain_name', '/api/me'));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAWrongCurrentPasswordIsRefusedNextToItsField(): void
    {
        [$client, $user] = $this->createLoggedInClient();

        $this->changePassword($client, 'not the password', self::NEW_PASSWORD);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame(['current_password' => ['The current password is wrong.']], self::violations($client));
        $this->assertTrue(self::isPassword($user, self::CURRENT_PASSWORD));
        $this->assertSame([], $this->rememberMeTokens->calls);
        $this->assertQueuedEmailCount(0);
    }

    /**
     * @return iterable<string, array{string, string, string}> current password, new password, message on new_password
     */
    public static function invalidNewPasswords(): iterable
    {
        yield 'blank' => [self::CURRENT_PASSWORD, '', 'Enter a new password.'];
        yield 'too short' => [self::CURRENT_PASSWORD, 'Sh0rt!pw', 'This value is too short. It should have 12 characters or more.'];
        yield 'weak' => [self::CURRENT_PASSWORD, 'aaaaaaaaaaaa', 'The password strength is too low. Please use a stronger password.'];
        yield 'same as the current one' => [self::CURRENT_PASSWORD, self::CURRENT_PASSWORD, 'The new password must be different from the current one.'];
    }

    #[DataProvider('invalidNewPasswords')]
    public function testAnInvalidNewPasswordIsRefusedNextToItsField(string $currentPassword, string $newPassword, string $message): void
    {
        [$client, $user] = $this->createLoggedInClient();

        $this->changePassword($client, $currentPassword, $newPassword);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertContains($message, self::violations($client)['new_password'] ?? []);
        $this->assertTrue(self::isPassword($user, self::CURRENT_PASSWORD));
        $this->assertQueuedEmailCount(0);
    }

    public function testTooManyWrongCurrentPasswordsBlockAlsoTheRightOne(): void
    {
        [$client, $user] = $this->createLoggedInClient();

        for ($i = 0; $i < 5; ++$i) {
            $this->changePassword($client, 'wrong '.$i, self::NEW_PASSWORD);
            $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->changePassword($client, self::CURRENT_PASSWORD, self::NEW_PASSWORD);

        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertResponseHasHeader('Retry-After');
        $this->assertSame('Too many attempts: try again in a few minutes.', self::jsonResponse($client)['detail']);
        $this->assertTrue(self::isPassword($user, self::CURRENT_PASSWORD));
    }

    public function testInvalidPayloadsDoNotCountAsAttempts(): void
    {
        [$client, $user] = $this->createLoggedInClient();

        // Refused before the current password is checked
        for ($i = 0; $i < 6; ++$i) {
            $this->changePassword($client, 'wrong '.$i, 'short');
            $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->changePassword($client, self::CURRENT_PASSWORD, self::NEW_PASSWORD);

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->assertTrue(self::isPassword($user, self::NEW_PASSWORD));
    }

    public function testASuccessfulChangeResetsTheAttempts(): void
    {
        [$client] = $this->createLoggedInClient();

        for ($i = 0; $i < 4; ++$i) {
            $this->changePassword($client, 'wrong '.$i, self::NEW_PASSWORD);
        }
        $this->changePassword($client, self::CURRENT_PASSWORD, self::NEW_PASSWORD);
        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->changePassword($client, 'wrong again', 'Another-Strong-Passphrase-99');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testNeedsALoggedInUser(): void
    {
        $client = $this->createClientWithoutDatabase();

        $this->changePassword($client, self::CURRENT_PASSWORD, self::NEW_PASSWORD);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}

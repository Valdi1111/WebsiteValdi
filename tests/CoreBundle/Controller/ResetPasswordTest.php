<?php

namespace App\Tests\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Repository\ResetPasswordRequestRepository;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Security\RememberMeTokenRevoker;
use App\Tests\CoreBundle\InMemoryResetPasswordRequestRepository;
use App\Tests\CoreBundle\RecordingRememberMeTokenRevoker;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Forgot password" pages through the whole HTTP stack (forms with their CSRF token, reset-password-bundle, rate
 * limiter), without a database: the reset links are kept in memory, the user comes from a stub repository and the
 * remember-me logins (a table) are only recorded; the emails stay in the in-memory core_async transport.
 *
 * The pages are rendered by React from the "data-props" of the page: the tests read and post those.
 */
class ResetPasswordTest extends WebTestCase
{
    use WithoutDatabaseClientTrait;

    private const string EMAIL = 'me@example.test';
    private const string OLD_PASSWORD = 'old Horse battery 7 staple';
    private const string NEW_PASSWORD = 'Correct-Horse-Battery-Staple-42';
    private const string RESET_LINK = '#https://[^\s"<]+/reset-password/([A-Za-z0-9]{40})#';

    private InMemoryResetPasswordRequestRepository $resetRequests;
    private RecordingRememberMeTokenRevoker $rememberMeTokens;
    private User $user;

    private function createAnonymousClient(): KernelBrowser
    {
        $client = $this->createClientWithoutDatabase();
        $container = static::getContainer();

        $this->resetRequests = new InMemoryResetPasswordRequestRepository();
        $container->set(ResetPasswordRequestRepository::class, $this->resetRequests);
        $this->rememberMeTokens = new RecordingRememberMeTokenRevoker();
        $container->set(RememberMeTokenRevoker::class, $this->rememberMeTokens);
        // No requests counted by another test or run
        $container->get('cache.rate_limiter')->clear();

        $this->user = $this->createUser([]);
        $this->user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($this->user, self::OLD_PASSWORD));
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')
            ->willReturnCallback(fn (array $criteria): ?User => $criteria === ['email' => self::EMAIL] ? $this->user : null);
        $container->set(UserRepository::class, $userRepository);

        return $client;
    }

    private static function pageUrl(string $path): string
    {
        return self::url('domain_name', $path);
    }

    /**
     * @return array{string, array<string, mixed>} the React page and its props
     */
    private static function page(KernelBrowser $client): array
    {
        $root = $client->getCrawler()->filter('#react-login-app');

        return [(string) $root->attr('data-page'), json_decode((string) $root->attr('data-props'), true)];
    }

    /**
     * Posts the form of the current page as the browser does: the fields by the names in the props, from the same
     * origin (the stateless CSRF token of the forms is checked against the Origin header).
     *
     * @param array<string, string> $values values by prop name, e.g. ['email' => '...']
     */
    private function submit(KernelBrowser $client, array $values): void
    {
        [, $props] = self::page($client);
        $fields = [];
        foreach ($values as $prop => $value) {
            parse_str(rawurlencode($props[$prop]['name']).'='.rawurlencode($value), $field);
            $fields = array_merge_recursive($fields, $field);
        }
        if ($props['csrf'] !== null) {
            parse_str(rawurlencode($props['csrf']['name']).'='.rawurlencode($props['csrf']['value']), $field);
            $fields = array_merge_recursive($fields, $field);
        }

        $client->request('POST', $props['action'], $fields, [], [
            'HTTP_ORIGIN' => 'https://'.static::getContainer()->getParameter('domain_name'),
        ]);
    }

    private function requestLink(KernelBrowser $client, string $email): void
    {
        $client->request('GET', self::pageUrl('/forgot-password'));
        $this->submit($client, ['email' => $email]);
    }

    private function resetLink(RawMessage $email): string
    {
        $this->assertMatchesRegularExpression(self::RESET_LINK, $email->getTextBody());
        preg_match(self::RESET_LINK, $email->getTextBody(), $matches);

        return $matches[0];
    }

    private function isPassword(string $password): bool
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->user, $password);
    }

    public function testALinkByEmailLetsTheUserChooseANewPasswordOnce(): void
    {
        $client = $this->createAnonymousClient();

        $this->requestLink($client, self::EMAIL);

        $this->assertResponseRedirects('/forgot-password/check-email');
        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'To', self::EMAIL);
        $this->assertEmailHeaderSame($email, 'Subject', 'Reset your password');
        $this->assertEmailHtmlBodyContains($email, 'expires in 1 hour');
        $link = $this->resetLink($email);
        // On the host the user asked from
        $this->assertStringStartsWith(self::pageUrl('/reset-password/'), $link);
        $this->assertCount(1, $this->resetRequests->rowsOf($this->user));

        $client->followRedirect();
        $this->assertSame(['check-email', '1 hour'], [self::page($client)[0], self::page($client)[1]['expiresIn']]);

        // The token leaves the URL for the session
        $client->request('GET', $link);
        $this->assertResponseRedirects('/reset-password');
        $client->followRedirect();
        [$page, $props] = self::page($client);
        $this->assertSame('reset-password', $page);
        $this->assertSame(self::EMAIL, $props['email']);
        $this->assertSame(12, $props['minLength']);

        $this->submit($client, ['password' => self::NEW_PASSWORD, 'confirmation' => self::NEW_PASSWORD]);

        $this->assertResponseRedirects('/login');
        $this->assertTrue($this->isPassword(self::NEW_PASSWORD));
        // Every link of the user deleted, every remember-me login too, without keeping this device
        $this->assertSame([], $this->resetRequests->rowsOf($this->user));
        $this->assertSame([[self::EMAIL, false]], $this->rememberMeTokens->calls);
        $this->assertQueuedEmailCount(1);
        $this->assertEmailHeaderSame($this->getMailerMessage(), 'Subject', 'Your password has been changed');

        $client->followRedirect();
        [$page, $props] = self::page($client);
        $this->assertSame('login', $page);
        $this->assertSame('Your password has been changed: log in with the new one.', $props['notice']);
        $this->assertSame('/forgot-password', $props['forgotPasswordPath']);

        // The link works once
        $client->request('GET', $link);
        $client->followRedirect();
        $this->assertResponseRedirects('/forgot-password');
        $client->followRedirect();
        $this->assertSame(['The reset password link is invalid. Please try to reset your password again.'], self::page($client)[1]['errors']);
    }

    public function testAnUnknownEmailGetsTheSameAnswerAndNoEmail(): void
    {
        $client = $this->createAnonymousClient();

        $this->requestLink($client, 'nobody@example.test');

        $this->assertResponseRedirects('/forgot-password/check-email');
        $this->assertQueuedEmailCount(0);
        $this->assertSame([], $this->resetRequests->rows);
    }

    public function testAnAccountGetsOneLinkPerHour(): void
    {
        $client = $this->createAnonymousClient();
        $this->requestLink($client, self::EMAIL);
        $this->assertQueuedEmailCount(1);

        $this->requestLink($client, self::EMAIL);

        // The same answer, and the first link still works
        $this->assertResponseRedirects('/forgot-password/check-email');
        $this->assertQueuedEmailCount(0);
        $this->assertCount(1, $this->resetRequests->rowsOf($this->user));
    }

    public function testAnInvalidEmailIsShownNextToTheField(): void
    {
        $client = $this->createAnonymousClient();

        $this->requestLink($client, 'not an email');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        [$page, $props] = self::page($client);
        $this->assertSame('forgot-password', $page);
        $this->assertSame('not an email', $props['email']['value']);
        $this->assertSame(['This value is not a valid email address.'], $props['email']['errors']);
        $this->assertQueuedEmailCount(0);
    }

    public function testRequestsFromTheSameAddressAreRateLimited(): void
    {
        $client = $this->createAnonymousClient();

        for ($i = 0; $i < 5; ++$i) {
            $this->requestLink($client, 'nobody'.$i.'@example.test');
            $this->assertResponseRedirects('/forgot-password/check-email');
        }
        $this->requestLink($client, self::EMAIL);

        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertQueuedEmailCount(0);
    }

    public function testAnUnknownLinkSendsBackToTheRequestPageWithTheReason(): void
    {
        $client = $this->createAnonymousClient();

        $client->request('GET', self::pageUrl('/reset-password/'.str_repeat('a', 40)));
        $client->followRedirect();

        $this->assertResponseRedirects('/forgot-password');
        $client->followRedirect();
        $this->assertSame(['The reset password link is invalid. Please try to reset your password again.'], self::page($client)[1]['errors']);
    }

    /**
     * @return iterable<string, array{string, string, string, string}> password, confirmation, prop, error
     */
    public static function invalidNewPasswords(): iterable
    {
        yield 'different confirmation' => [self::NEW_PASSWORD, self::NEW_PASSWORD.'!', 'password', 'The two passwords do not match.'];
        yield 'too short' => ['Sh0rt!pw', 'Sh0rt!pw', 'password', 'This value is too short. It should have 12 characters or more.'];
        yield 'weak' => ['aaaaaaaaaaaa', 'aaaaaaaaaaaa', 'password', 'The password strength is too low. Please use a stronger password.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNewPasswords')]
    public function testAnInvalidNewPasswordIsShownNextToTheField(string $password, string $confirmation, string $prop, string $error): void
    {
        $client = $this->createAnonymousClient();
        $this->requestLink($client, self::EMAIL);
        $client->request('GET', $this->resetLink($this->getMailerMessage()));
        $client->followRedirect();

        $this->submit($client, ['password' => $password, 'confirmation' => $confirmation]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertContains($error, self::page($client)[1][$prop]['errors']);
        $this->assertTrue($this->isPassword(self::OLD_PASSWORD));
        // The link still works
        $this->assertCount(1, $this->resetRequests->rowsOf($this->user));
        $this->assertSame([], $this->rememberMeTokens->calls);
    }

    public function testTheResetPageWithoutALinkIsNotFound(): void
    {
        $client = $this->createAnonymousClient();

        $client->request('GET', self::pageUrl('/reset-password'));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheLoginPageLinksToTheRequestPage(): void
    {
        $client = $this->createAnonymousClient();

        $client->request('GET', self::url('books.domain_name', '/login'));

        $this->assertResponseIsSuccessful();
        [$page, $props] = self::page($client);
        $this->assertSame('login', $page);
        $this->assertSame('/forgot-password', $props['forgotPasswordPath']);
        $this->assertNull($props['notice']);
    }
}

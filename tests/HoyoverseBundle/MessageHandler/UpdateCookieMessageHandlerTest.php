<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Exception\HoyolabUnavailableException;
use App\HoyoverseBundle\MessageHandler\UpdateCookieMessageHandler;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Repository\HoyoverseAccountRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Scheduled cookie refresh: only a refusal from HoYoverse flags the account; an outage changes nothing.
 */
class UpdateCookieMessageHandlerTest extends TestCase
{
    private const string COOKIE = 'ltoken_v2=v2_old; ltuid_v2=111; ltmid_v2=mid111; stoken_v2=v2_st';

    private function createHandler(\Closure $renew, UnifiedNotificationService $notificationService): UpdateCookieMessageHandler
    {
        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);
        $cookieUtils->method('parseCookie')->willReturn((new ParsedCookie())->setLtokenV2('v2_old')->setLtuidV2('111')->setLtmidV2('mid111')->setStokenV2('v2_st'));
        $cookieUtils->method('renew')->willReturnCallback($renew);
        $cookieUtils->method('getLogger')->willReturn(new NullLogger());

        return new UpdateCookieMessageHandler(
            $this->createStub(HoyoverseAccountRepository::class),
            $cookieUtils,
            $this->createStub(EntityManagerInterface::class),
            $notificationService,
        );
    }

    private static function account(bool $refreshFailed): HoyoverseAccount
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);

        return (new HoyoverseAccount())->setUser($user)->setCookie(self::COOKIE)->setCookieRefreshFailed($refreshFailed);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function previousStateProvider(): iterable
    {
        yield 'healthy account' => [false];
        yield 'already flagged account' => [true];
    }

    #[DataProvider('previousStateProvider')]
    public function testOutageKeepsTheAccountStateAndSendsNothing(bool $refreshFailed): void
    {
        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');
        $account = self::account($refreshFailed);

        $this->createHandler(static fn (): bool => throw new HoyolabUnavailableException('HoYoverse passport API could not be reached.'), $notificationService)
            ->processAccount($account);

        $this->assertSame($refreshFailed, $account->isCookieRefreshFailed());
        $this->assertSame(self::COOKIE, $account->getCookie());
        $this->assertNull($account->getLastCookieRefreshedAt());
    }

    public function testRefusalFlagsTheAccountAndNotifiesOnce(): void
    {
        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->once())->method('sendToUser');
        $account = self::account(false);

        $this->createHandler(static fn (): bool => false, $notificationService)->processAccount($account);

        $this->assertTrue($account->isCookieRefreshFailed());
    }
}

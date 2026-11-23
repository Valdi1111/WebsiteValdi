<?php

namespace App\Tests\CoreBundle\Security;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Security\RememberMeTokenRevoker;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

/**
 * The rows deleted and the cookie issued again: the table and the remember-me handler are mocks.
 */
class RememberMeTokenRevokerTest extends TestCase
{
    private static function user(): User
    {
        return (new User())->setEmail('me@example.test')->setPassword('hash');
    }

    private static function requestStack(bool $rememberedDevice): RequestStack
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(cookies: $rememberedDevice ? ['REMEMBERME' => 'cookie value'] : []));

        return $requestStack;
    }

    private function revoker(bool $rememberedDevice, int $cookiesIssued): RememberMeTokenRevoker
    {
        $connection = $this->createMock(Connection::class);
        // Every row of the user, the current device's too: its token is bound to the old password
        $connection->expects($this->once())->method('delete')->with('rememberme_token', ['username' => 'me@example.test']);

        $rememberMeHandler = $this->createMock(RememberMeHandlerInterface::class);
        $rememberMeHandler->expects($this->exactly($cookiesIssued))->method('createRememberMeCookie');

        return new RememberMeTokenRevoker($connection, $rememberMeHandler, self::requestStack($rememberedDevice));
    }

    public function testKeepingTheCurrentDeviceIssuesItANewCookie(): void
    {
        $this->revoker(rememberedDevice: true, cookiesIssued: 1)->revoke(self::user(), true);
    }

    public function testADeviceThatWasNotRememberedGetsNoCookie(): void
    {
        $this->revoker(rememberedDevice: false, cookiesIssued: 0)->revoke(self::user(), true);
    }

    public function testWithoutKeepingTheCurrentDeviceNoCookieIsIssued(): void
    {
        $this->revoker(rememberedDevice: true, cookiesIssued: 0)->revoke(self::user(), false);
    }
}

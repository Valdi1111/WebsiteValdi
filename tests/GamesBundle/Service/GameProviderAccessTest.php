<?php

namespace App\Tests\GamesBundle\Service;

use App\GamesBundle\Service\GameProviderAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class GameProviderAccessTest extends TestCase
{
    private const array PROVIDERS = [
        'hoyoverse' => 'ROLE_USER_HOYOVERSE',
        'gryphline' => 'ROLE_USER_GRYPHLINE',
    ];

    /**
     * @param list<string> $grantedRoles
     */
    private function createAccess(array $grantedRoles): GameProviderAccess
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')
            ->willReturnCallback(static fn (mixed $attribute): bool => in_array($attribute, $grantedRoles, true));

        return new GameProviderAccess($checker, self::PROVIDERS);
    }

    public function testReturnsAllProvidersInConfiguredOrder(): void
    {
        $access = $this->createAccess(['ROLE_USER_GRYPHLINE', 'ROLE_USER_HOYOVERSE']);

        $this->assertSame(['hoyoverse', 'gryphline'], $access->getGrantedProviders());
    }

    public function testReturnsOnlyGrantedProviders(): void
    {
        $access = $this->createAccess(['ROLE_USER_GRYPHLINE']);

        $this->assertSame(['gryphline'], $access->getGrantedProviders());
    }

    public function testReturnsNoProviderWithoutProviderRoles(): void
    {
        $access = $this->createAccess(['ROLE_USER_GAMES']);

        $this->assertSame([], $access->getGrantedProviders());
    }
}

<?php

namespace App\Tests\PerfectworldBundle\Service;

use App\CoreBundle\Entity\User;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Exception\PerfectworldAuthException;
use App\PerfectworldBundle\Model\Sdk\PwRole;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\PerfectworldProfileSyncService;
use App\PerfectworldBundle\Service\PerfectworldSdkClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

// Collaborators are mocks shared by all tests; each test sets expectations only on what it checks
#[AllowMockObjectsWithoutExpectations]
class PerfectworldProfileSyncServiceTest extends TestCase
{
    private function createService(PerfectworldSdkClient $sdk, EntityManagerInterface $entityManager): PerfectworldProfileSyncService
    {
        $game = new NevernessToEvernessService(new NullLogger());

        return new PerfectworldProfileSyncService(
            $entityManager,
            $sdk,
            new NteCatalog(new ArrayAdapter()),
            new ServiceLocator([(string) NevernessToEvernessService::GAME_ID => static fn () => $game]),
        );
    }

    private static function createAccount(): PerfectworldAccount
    {
        return (new PerfectworldAccount())
            ->setUser(new User())
            ->setPwUid('2000000001')
            ->setToken('token-1234')
            ->setNdid(str_repeat('a', 64));
    }

    public function testRolesBecomeNteProfilesWithRegionAndTimezone(): void
    {
        $account = self::createAccount()->setTokenInvalid(true);
        $sdk = $this->createMock(PerfectworldSdkClient::class);
        $sdk->expects($this->once())->method('getRoles')->with('2000000001', 'token-1234')->willReturn([
            new PwRole('100000000001', 'Player_1', '23003', 'Europe', 58, 'Fashion_1042_1', '2026-04-29 10:33:00', null),
        ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(PerfectworldGameProfile::class));
        $entityManager->expects($this->once())->method('flush');

        $this->createService($sdk, $entityManager)->syncProfiles($account);

        $profile = $account->getGameProfiles()->first();
        $this->assertInstanceOf(PerfectworldGameProfile::class, $profile);
        $this->assertSame('100000000001', $profile->getGameUid());
        $this->assertSame(NevernessToEvernessService::GAME_ID, $profile->getGameId());
        $this->assertSame('nte', $profile->getGameBiz());
        $this->assertSame('Player_1', $profile->getNickname());
        $this->assertSame(58, $profile->getLevel());
        $this->assertSame('EU', $profile->getRegion());
        $this->assertSame('Europe', $profile->getRegionName());
        $this->assertSame('Etc/GMT-1', $profile->getParsedTimezone());
        $this->assertSame('Fashion_1042_1', $profile->getAvatarId());
        $this->assertSame('2026-04-29 10:33:00', $profile->getFirstLoginAt()?->format('Y-m-d H:i:s'));
        $this->assertFalse($account->isTokenInvalid());
        $this->assertNotNull($account->getLastSyncedAt());
    }

    public function testExistingProfilesAreUpdatedInPlace(): void
    {
        $account = self::createAccount();
        $existing = (new PerfectworldGameProfile())->setGameUid('100000000001')->setNickname('Old name')->setLevel(40);
        $account->addGameProfile($existing);

        $sdk = $this->createMock(PerfectworldSdkClient::class);
        $sdk->method('getRoles')->willReturn([new PwRole('100000000001', 'New name', '23003', 'Europe', 58, null, null, null)]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $this->createService($sdk, $entityManager)->syncProfiles($account);

        $this->assertCount(1, $account->getGameProfiles());
        $this->assertSame('New name', $existing->getNickname());
        $this->assertSame(58, $existing->getLevel());
    }

    public function testExpiredTokenFlagsTheAccountBeforeRethrowing(): void
    {
        $account = self::createAccount();
        $sdk = $this->createMock(PerfectworldSdkClient::class);
        $sdk->method('getRoles')->willThrowException(new PerfectworldAuthException());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        try {
            $this->createService($sdk, $entityManager)->syncProfiles($account);
            $this->fail('Expected a PerfectworldAuthException.');
        } catch (PerfectworldAuthException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertTrue($account->isTokenInvalid());
    }
}

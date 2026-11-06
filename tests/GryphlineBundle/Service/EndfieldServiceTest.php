<?php

namespace App\Tests\GryphlineBundle\Service;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Game\RuntimeAccountDataInterface;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Model\Notes\EndfieldNotes;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use App\GryphlineBundle\Service\EndfieldService;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EndfieldServiceTest extends TestCase
{
    private function createService(
        ?HttpClientInterface $client = null,
        ?SkportSignerService $signer = null,
        ?SkportAuthService $auth = null,
        ?SkportUtilsService $utils = null,
        ?DenormalizerInterface $denormalizer = null
    ): EndfieldService {
        return new EndfieldService(
            $client ?? $this->createStub(HttpClientInterface::class),
            $signer ?? $this->createStub(SkportSignerService::class),
            $auth ?? $this->createStub(SkportAuthService::class),
            $utils ?? $this->createStub(SkportUtilsService::class),
            $denormalizer ?? $this->createStub(DenormalizerInterface::class),
            new NullLogger()
        );
    }

    public function testEnsureCredentialsThrowsWhenInvalidAccountTypePassed(): void
    {
        $service = $this->createService();
        $invalidAccount = $this->createStub(RuntimeAccountDataInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected App\GryphlineBundle\Model\RuntimeAccountData');

        $service->ensureCredentials($invalidAccount);
    }

    public function testEnsureCredentialsReusesValidCredentials(): void
    {
        $account = new GryphlineAccount();
        $profile = (new GryphlineGameProfile())->setAccount($account);
        $runtime = (new RuntimeAccountData())->setGameProfile($profile);

        $validCreds = new SkportSessionCredentials(
            cred: 'test-cred',
            salt: 'test-salt',
            userId: '12345',
            expiresAt: new \DateTimeImmutable('+1 hour')
        );
        $runtime->setCredentials($validCreds);

        $auth = $this->createMock(SkportAuthService::class);
        $auth->expects($this->never())->method('getOrRefreshCredentials');

        $service = $this->createService(auth: $auth);
        $result = $service->ensureCredentials($runtime, force: false);

        $this->assertSame($validCreds, $result);
    }

    public function testEnsureCredentialsRefreshesWhenExpiredOrForced(): void
    {
        $account = new GryphlineAccount();
        $profile = (new GryphlineGameProfile())->setAccount($account);
        $runtime = (new RuntimeAccountData())->setGameProfile($profile);

        $expiredCreds = new SkportSessionCredentials(
            cred: 'old-cred',
            salt: 'old-salt',
            userId: '12345',
            expiresAt: new \DateTimeImmutable('-10 minutes')
        );
        $runtime->setCredentials($expiredCreds);

        $newCreds = new SkportSessionCredentials(
            cred: 'new-cred',
            salt: 'new-salt',
            userId: '12345',
            expiresAt: new \DateTimeImmutable('+45 minutes')
        );

        $auth = $this->createMock(SkportAuthService::class);
        $auth->expects($this->once())
            ->method('getOrRefreshCredentials')
            ->with($account, false)
            ->willReturn($newCreds);

        $service = $this->createService(auth: $auth);
        $result = $service->ensureCredentials($runtime, force: false);

        $this->assertSame($newCreds, $result);
        $this->assertSame($newCreds, $runtime->getCredentials());
    }

    public function testConstantsAndMetadata(): void
    {
        $service = $this->createService();
        $this->assertSame(3, EndfieldService::getGameId());
        $this->assertSame('endfield', EndfieldService::getGameBiz());
        $this->assertSame('Arknights: Endfield', $service->getGameName());
        $this->assertSame(360, $service->getMaxStamina());
        $this->assertSame(360, $service->getRegenRate());
        $this->assertSame('/api/v1/game/endfield/card/detail', $service->getUrlNotes());
        $this->assertSame(EndfieldNotes::class, $service->getNotesClass());
    }
}

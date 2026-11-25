<?php

namespace App\Tests\GryphlineBundle\Service;

use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Exception\SkportApiException;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Per-profile notes cache of the SKPort NotesTrait, with the application serializer.
 */
class ArknightsEndfieldNotesCacheTest extends KernelTestCase
{
    private int $requests = 0;

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(300);
    }

    /**
     * @param list<MockResponse> $responses SKPort answers, one per request in order
     */
    private function createService(array $responses, ?SkportAuthService $auth = null): ArknightsEndfieldService
    {
        self::bootKernel();

        $client = new MockHttpClient(function () use (&$responses): MockResponse {
            $this->requests++;

            return array_shift($responses) ?? self::answer(['code' => -1, 'message' => 'unexpected request']);
        }, 'https://zonai.skport.com');

        $service = new ArknightsEndfieldService(
            $client,
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SkportSignerService::class),
            $auth ?? $this->createStub(SkportAuthService::class),
            $this->createStub(SkportUtilsService::class),
            static::getContainer()->get('serializer'),
            $this->createStub(ObjectMapperInterface::class),
            new NullLogger()
        );
        $service->setNotesCache($this->cache);
        $service->setNotesClock(new MockClock('2026-11-22 10:00:00', 'UTC'));

        return $service;
    }

    private function createAccount(): RuntimeAccountData
    {
        $profile = (new GryphlineGameProfile())
            ->setAccount(new GryphlineAccount())
            ->setGameId(ArknightsEndfieldService::GAME_ID)
            ->setGameUid('4000000001')
            ->setServerId('2');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, 9);

        return (new RuntimeAccountData())
            ->setGameProfile($profile)
            ->setCredentials(self::credentials('cred'));
    }

    private static function credentials(string $cred): SkportSessionCredentials
    {
        return new SkportSessionCredentials(cred: $cred, salt: 'salt', userId: '12345', expiresAt: new \DateTimeImmutable('+1 hour'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function detail(int $stamina = 200): array
    {
        return [
            'dungeon' => ['curStamina' => (string) $stamina, 'maxStamina' => '360', 'maxTs' => '1795400000'],
            'dailyMission' => ['dailyActivation' => 60, 'maxDailyActivation' => 100],
            'weeklyMission' => ['score' => 4, 'total' => 10],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private static function success(int $stamina = 200): MockResponse
    {
        return self::answer(['code' => 0, 'message' => 'OK', 'data' => ['detail' => self::detail($stamina)]]);
    }

    public function testDataGettersShareOneRequestAndTheEntryHoldsTheDetail(): void
    {
        $service = $this->createService([self::success()]);
        $account = $this->createAccount();

        $this->assertSame(200, $service->getStaminaData($account)->getCurrentStamina());
        $this->assertSame(60, $service->getDailiesData($account)->first()->getCurrentValue());
        $this->assertSame(4, $service->getWeekliesData($account)->first()->getCurrentValue());

        $this->assertSame(1, $this->requests);
        $this->assertSame(
            ['fetchedAt' => (new \DateTimeImmutable('2026-11-22 10:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 'data' => self::detail()],
            $this->cache->getItem('notes_9')->get()
        );
    }

    public function testFreshCallsTheApiAgain(): void
    {
        $service = $this->createService([self::success(100), self::success(150)]);
        $account = $this->createAccount();

        $this->assertSame(100, $service->getNotes($account)->getCurStamina());
        $this->assertSame(150, $service->getNotes($account, fresh: true)->getCurStamina());
        $this->assertSame(150, $service->getNotes($account)->getCurStamina());
        $this->assertSame(2, $this->requests);
    }

    public function testExpiredSessionIsRefreshedOnceAndTheRetryIsCached(): void
    {
        $auth = $this->createMock(SkportAuthService::class);
        $auth->expects($this->once())
            ->method('getOrRefreshCredentials')
            ->with($this->isInstanceOf(GryphlineAccount::class), true)
            ->willReturn(self::credentials('new-cred'));

        $service = $this->createService([self::answer(['code' => 10000, 'message' => 'unauthorized'], 401), self::success(120)], $auth);
        $account = $this->createAccount();

        $this->assertSame(120, $service->getStaminaData($account)->getCurrentStamina());
        $this->assertSame(120, $service->getStaminaData($account)->getCurrentStamina());

        $this->assertSame(2, $this->requests);
        $this->assertTrue($this->cache->getItem('notes_9')->isHit());
    }

    public function testFailedAnswersAreNotCached(): void
    {
        $service = $this->createService([
            self::answer(['code' => 0], 500),
            self::answer(['code' => 10001, 'message' => 'role not found']),
            self::success(90),
        ]);
        $account = $this->createAccount();

        foreach (['HTTP error', 'code error'] as $case) {
            try {
                $service->getNotes($account);
                $this->fail("The {$case} should throw");
            } catch (SkportApiException) {
            }
            $this->assertFalse($this->cache->getItem('notes_9')->isHit(), $case);
        }

        $this->assertSame(90, $service->getStaminaData($account)->getCurrentStamina());
        $this->assertSame(3, $this->requests);
    }

    public function testContainerWiresTheNotesPool(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->assertSame($container->get('gryphline.notes.cache'), $container->get(ArknightsEndfieldService::class)->getNotesCache());
    }
}

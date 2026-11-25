<?php

namespace App\Tests\GryphlineBundle\Service;

use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Exception\SkportApiException;
use App\GryphlineBundle\Model\RuntimeAccountData;
use App\GryphlineBundle\Model\SkportSessionCredentials;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use App\HoyoverseBundle\Model\RuntimeAccountData as HoyoverseRuntimeAccountData;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only, per-profile cached check-in status of the SKPort CheckInTrait, with the application serializer.
 */
class ArknightsEndfieldCheckInStatusTest extends KernelTestCase
{
    private const string NOW = '2026-11-22 10:00:00';
    private const string ATTENDANCE_PATH = '/web/v1/game/endfield/attendance';

    /** @var list<array{method: string, path: string, cred: string|null}> */
    private array $requests = [];

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(600);
    }

    /**
     * @param list<MockResponse> $responses SKPort answers, one per request in order
     */
    private function createService(array $responses, ?SkportAuthService $auth = null, string $timezone = 'UTC'): ArknightsEndfieldService
    {
        self::bootKernel();

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $cred = $options['normalized_headers']['cred'][0] ?? null;
            $this->requests[] = [
                'method' => $method,
                'path' => (string) parse_url($url, PHP_URL_PATH),
                'cred' => $cred !== null ? substr($cred, strlen('cred: ')) : null,
            ];

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
        $service->setCheckInStatusCache($this->cache);
        $service->setNotesClock(new MockClock(self::NOW, $timezone));

        return $service;
    }

    private function createAccount(?int $profileId = 9): RuntimeAccountData
    {
        $profile = (new GryphlineGameProfile())
            ->setAccount(new GryphlineAccount())
            ->setGameId(ArknightsEndfieldService::GAME_ID)
            ->setGameUid('4000000001')
            ->setServerId('2');
        if ($profileId !== null) {
            (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, $profileId);
        }

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
    private static function attendance(bool $hasToday = false, int $doneDays = 3): array
    {
        $calendar = [];
        for ($day = 1; $day <= 7; ++$day) {
            $calendar[] = ['awardId' => "award_{$day}", 'available' => $day === $doneDays + 1 && !$hasToday, 'done' => $day <= $doneDays];
        }

        $resources = [];
        foreach ($calendar as $record) {
            $resources[$record['awardId']] = ['id' => $record['awardId'], 'count' => 10, 'name' => 'Oroberyl', 'icon' => null];
        }

        return ['currentTs' => '1795300000', 'calendar' => $calendar, 'resourceInfoMap' => $resources, 'hasToday' => $hasToday];
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

    private static function success(bool $hasToday = false, int $doneDays = 3): MockResponse
    {
        return self::answer(['code' => 0, 'message' => 'OK', 'data' => self::attendance($hasToday, $doneDays)]);
    }

    private static function claimed(int $day): MockResponse
    {
        return self::answer(['code' => 0, 'message' => 'OK', 'data' => ['awardIds' => [['id' => "award_{$day}", 'type' => 0]]]]);
    }

    private function assertOnlyAttendanceReads(int $count): void
    {
        $this->assertCount($count, $this->requests);
        foreach ($this->requests as $request) {
            $this->assertSame('GET', $request['method'], 'A status read must never send the claim (POST)');
            $this->assertSame(self::ATTENDANCE_PATH, $request['path']);
        }
    }

    public function testStatusIsReadOnlyAndCachedPerProfile(): void
    {
        $service = $this->createService([self::success(hasToday: true, doneDays: 4)]);
        $account = $this->createAccount();

        $this->assertInstanceOf(HasCheckInStatusInterface::class, $service);
        $first = $service->getCheckInStatus($account);
        $second = $service->getCheckInStatus($account);

        $this->assertOnlyAttendanceReads(1);
        $this->assertTrue($first->signedToday);
        $this->assertSame(4, $first->totalDays);
        $this->assertEquals(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $first->fetchedAt);
        $this->assertEquals($first, $second);
        $this->assertSame(
            ['fetchedAt' => (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->getTimestamp(), 'data' => self::attendance(true, 4)],
            $this->cache->getItem('check_in_9')->get()
        );
    }

    public function testEachProfileHasItsOwnEntry(): void
    {
        $service = $this->createService([self::success(hasToday: false), self::success(hasToday: true)]);
        $first = $this->createAccount(1);
        $second = $this->createAccount(2);

        $this->assertFalse($service->getCheckInStatus($first)->signedToday);
        $this->assertTrue($service->getCheckInStatus($second)->signedToday);
        $this->assertFalse($service->getCheckInStatus($first)->signedToday);
        $this->assertTrue($service->getCheckInStatus($second)->signedToday);

        $this->assertOnlyAttendanceReads(2);
    }

    public function testFreshReadsAgainAndReplacesTheEntry(): void
    {
        $service = $this->createService([self::success(hasToday: false, doneDays: 3), self::success(hasToday: true, doneDays: 4)]);
        $account = $this->createAccount();

        $this->assertFalse($service->getCheckInStatus($account)->signedToday);
        $fresh = $service->getCheckInStatus($account, fresh: true);
        $this->assertTrue($fresh->signedToday);
        $this->assertSame(4, $fresh->totalDays);
        $this->assertTrue($service->getCheckInStatus($account)->signedToday);

        $this->assertOnlyAttendanceReads(2);
        $this->assertTrue($this->cache->getItem('check_in_9')->get()['data']['hasToday']);
    }

    public function testFailedAnswersAreNotCached(): void
    {
        $service = $this->createService([
            self::answer(['code' => 0], 500),
            self::answer(['code' => 10001, 'message' => 'role not found']),
            self::success(hasToday: true),
        ]);
        $account = $this->createAccount();

        foreach (['HTTP error', 'code error'] as $case) {
            try {
                $service->getCheckInStatus($account);
                $this->fail("The {$case} should throw");
            } catch (SkportApiException) {
            }
            $this->assertFalse($this->cache->getItem('check_in_9')->isHit(), $case);
        }

        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertOnlyAttendanceReads(3);
    }

    public function testAnswerWithoutDataReadsAsNotSignedAndIsNotCached(): void
    {
        $service = $this->createService([self::answer(['code' => 0, 'message' => 'OK']), self::success(hasToday: true)]);
        $account = $this->createAccount();

        $status = $service->getCheckInStatus($account);
        $this->assertFalse($status->signedToday);
        $this->assertSame(0, $status->totalDays);
        $this->assertFalse($this->cache->getItem('check_in_9')->isHit());

        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertOnlyAttendanceReads(2);
    }

    public function testProfileWithoutIdIsNeverCached(): void
    {
        $service = $this->createService([self::success(hasToday: false), self::success(hasToday: true)]);
        $account = $this->createAccount(profileId: null);

        $this->assertFalse($service->getCheckInStatus($account)->signedToday);
        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertOnlyAttendanceReads(2);
    }

    public function testFetchedAtComesFromTheEntryInTheClockTimezone(): void
    {
        $fetchedAt = new \DateTimeImmutable('2026-11-22 09:55:00', new \DateTimeZone('UTC'));
        $this->cache->save($this->cache->getItem('check_in_9')->set([
            'fetchedAt' => $fetchedAt->getTimestamp(),
            'data' => self::attendance(hasToday: true, doneDays: 5),
        ]));
        $service = $this->createService([], timezone: 'Europe/Rome');

        $status = $service->getCheckInStatus($this->createAccount());

        $this->assertSame([], $this->requests);
        $this->assertTrue($status->signedToday);
        $this->assertSame(5, $status->totalDays);
        $this->assertSame($fetchedAt->getTimestamp(), $status->fetchedAt->getTimestamp());
        $this->assertSame('Europe/Rome', $status->fetchedAt->getTimezone()->getName());
    }

    public function testExpiredSessionIsRefreshedOnceAndTheRetryIsCached(): void
    {
        $auth = $this->createMock(SkportAuthService::class);
        $auth->expects($this->once())
            ->method('getOrRefreshCredentials')
            ->with($this->isInstanceOf(GryphlineAccount::class), true)
            ->willReturn(self::credentials('new-cred'));

        $service = $this->createService([self::answer(['code' => 10000, 'message' => 'unauthorized'], 401), self::success(hasToday: true)], $auth);
        $account = $this->createAccount();

        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertTrue($service->getCheckInStatus($account)->signedToday);

        $this->assertOnlyAttendanceReads(2);
        $this->assertSame(['cred', 'new-cred'], array_column($this->requests, 'cred'));
        $this->assertTrue($this->cache->getItem('check_in_9')->get()['data']['hasToday']);
    }

    public function testSecondExpiredSessionIsNotRetriedNorCached(): void
    {
        $auth = $this->createMock(SkportAuthService::class);
        $auth->expects($this->once())
            ->method('getOrRefreshCredentials')
            ->willReturn(self::credentials('new-cred'));

        $service = $this->createService([
            self::answer(['code' => 10000, 'message' => 'unauthorized'], 401),
            self::answer(['code' => 10000, 'message' => 'unauthorized'], 401),
        ], $auth);

        try {
            $service->getCheckInStatus($this->createAccount());
            $this->fail('The second 401 should throw');
        } catch (SkportApiException $e) {
            $this->assertSame(401, $e->getApiStatusCode());
        }

        $this->assertOnlyAttendanceReads(2);
        $this->assertFalse($this->cache->getItem('check_in_9')->isHit());
    }

    public function testClaimDeletesTheEntry(): void
    {
        $service = $this->createService([
            self::success(hasToday: false, doneDays: 3), // status
            self::success(hasToday: false, doneDays: 3), // check-in: attendance
            self::claimed(4),                            // check-in: claim
            self::success(hasToday: true, doneDays: 4),  // status again
        ]);
        $account = $this->createAccount();

        $this->assertFalse($service->getCheckInStatus($account)->signedToday);
        $this->assertTrue($this->cache->getItem('check_in_9')->isHit());

        $result = $service->checkIn($account);

        $this->assertSame('claimed', $result->getStatus());
        $this->assertSame(4, $result->getTotalSignDay());
        $this->assertFalse($this->cache->getItem('check_in_9')->isHit());
        $this->assertSame(['POST', self::ATTENDANCE_PATH], [$this->requests[2]['method'], $this->requests[2]['path']]);

        $status = $service->getCheckInStatus($account);
        $this->assertTrue($status->signedToday);
        $this->assertSame(4, $status->totalDays);
        $this->assertCount(4, $this->requests);
    }

    public function testAlreadyClaimedCheckInDeletesTheEntry(): void
    {
        $this->cache->save($this->cache->getItem('check_in_9')->set([
            'fetchedAt' => (new \DateTimeImmutable(self::NOW))->getTimestamp(),
            'data' => self::attendance(hasToday: false, doneDays: 3),
        ]));
        $service = $this->createService([
            self::success(hasToday: true, doneDays: 4), // check-in: attendance
            self::success(hasToday: true, doneDays: 4), // status
        ]);
        $account = $this->createAccount();

        $result = $service->checkIn($account);

        $this->assertSame('already_claimed', $result->getStatus());
        $this->assertSame(4, $result->getTotalSignDay());
        $this->assertFalse($this->cache->getItem('check_in_9')->isHit());

        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertOnlyAttendanceReads(2);
    }

    public function testFailedClaimKeepsTheEntry(): void
    {
        $service = $this->createService([
            self::success(hasToday: false), // status
            self::success(hasToday: false), // check-in: attendance
            self::answer(['code' => 10001, 'message' => 'claim failed']),
        ]);
        $account = $this->createAccount();
        $service->getCheckInStatus($account);

        try {
            $service->checkIn($account);
            $this->fail('The failed claim should throw');
        } catch (SkportApiException) {
        }

        $this->assertTrue($this->cache->getItem('check_in_9')->isHit());
    }

    public function testStatusRejectsAnotherAccountType(): void
    {
        $service = $this->createService([]);

        $this->expectException(\InvalidArgumentException::class);
        $service->getCheckInStatus(new HoyoverseRuntimeAccountData());
    }

    public function testContainerWiresTheCheckInStatusPool(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->assertSame($container->get('gryphline.check_in_status.cache'), $container->get(ArknightsEndfieldService::class)->getCheckInStatusCache());
    }
}

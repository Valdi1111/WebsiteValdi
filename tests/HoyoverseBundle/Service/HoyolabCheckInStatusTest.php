<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GryphlineBundle\Model\RuntimeAccountData as GryphlineRuntimeAccountData;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveSignInfoException;
use App\HoyoverseBundle\Exception\SignInFailedException;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\TearsOfThemisService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Read-only, per-profile cached check-in status of the HoYoLAB check-in trait, with the application serializer.
 */
class HoyolabCheckInStatusTest extends KernelTestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(600);
    }

    /**
     * @return iterable<string, array{class-string<GameService>}>
     */
    public static function gameServices(): iterable
    {
        yield 'Genshin Impact' => [GenshinImpactService::class];
        yield 'Honkai: Star Rail' => [HonkaiStarRailService::class];
        yield 'Zenless Zone Zero' => [ZenlessZoneZeroService::class];
        yield 'Honkai Impact 3rd' => [HonkaiImpact3rdService::class];
        yield 'Tears of Themis' => [TearsOfThemisService::class];
    }

    /**
     * @param list<array<string, mixed>> $bodies HoYoLAB answers, one per request in order
     * @param class-string<GameService>  $class
     */
    private function createService(array $bodies, string $class = GenshinImpactService::class, string $timezone = 'UTC'): GameService
    {
        self::bootKernel();

        $service = new $class(new NullLogger());
        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setCheckInStatusCache($this->cache);
        $service->setCheckInStatusClock(new MockClock(self::NOW, $timezone));
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url) use (&$bodies): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => strtok($url, '?')];
            $body = array_shift($bodies) ?? ['retcode' => -1, 'message' => 'unexpected request'];

            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        }));

        return $service;
    }

    private function createAccount(?int $profileId = 42, string $gameUid = '700000001'): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())
            ->setGameId(GenshinImpactService::GAME_ID)
            ->setGameUid($gameUid)
            ->setRegion('os_euro')
            ->setNickname('Traveler');
        if ($profileId !== null) {
            (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, $profileId);
        }

        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile($profile);
    }

    /**
     * @return array<string, mixed>
     */
    private static function infoData(bool $isSign = false, int $totalSignDay = 12): array
    {
        return [
            'total_sign_day' => $totalSignDay,
            'today' => '2026-11-22',
            'is_sign' => $isSign,
            'first_bind' => false,
            'is_sub' => false,
            'region' => '',
            'month_last_day' => false,
        ];
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    private static function answer(?array $data, int $retcode = 0, string $message = 'OK'): array
    {
        return ['retcode' => $retcode, 'message' => $message, 'data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    private static function awardsAnswer(): array
    {
        $awards = [];
        for ($day = 1; $day <= 30; ++$day) {
            $awards[] = ['icon' => "https://example.test/{$day}.png", 'name' => "Reward {$day}", 'cnt' => $day];
        }

        return self::answer(['month' => 11, 'awards' => $awards]);
    }

    /**
     * @param list<array{method: string, url: string}> $requests
     */
    private function assertOnlyInfoRequests(GameService $service, array $requests, int $count): void
    {
        $this->assertCount($count, $requests);
        foreach ($requests as $request) {
            $this->assertSame(['method' => 'GET', 'url' => $service->getUrlInfo()], $request);
            $this->assertNotSame($service->getUrlSign(), $request['url']);
        }
    }

    #[DataProvider('gameServices')]
    public function testStatusIsReadOnlyAndCachedPerProfile(string $class): void
    {
        $service = $this->createService([self::answer(self::infoData(isSign: true, totalSignDay: 12))], $class);
        $account = $this->createAccount();

        $this->assertInstanceOf(HasCheckInStatusInterface::class, $service);
        $first = $service->getCheckInStatus($account);
        $second = $service->getCheckInStatus($account);

        $this->assertOnlyInfoRequests($service, $this->requests, 1);
        $this->assertTrue($first->signedToday);
        $this->assertSame(12, $first->totalDays);
        $this->assertEquals(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $first->fetchedAt);
        $this->assertEquals($first, $second);
        $this->assertSame(
            ['fetchedAt' => (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->getTimestamp(), 'data' => self::infoData(isSign: true, totalSignDay: 12)],
            $this->cache->getItem('check_in_42')->get()
        );
    }

    public function testEachProfileHasItsOwnEntry(): void
    {
        $service = $this->createService([self::answer(self::infoData(isSign: false)), self::answer(self::infoData(isSign: true))]);
        $first = $this->createAccount(1, '700000001');
        $second = $this->createAccount(2, '700000002');

        $this->assertFalse($service->getCheckInStatus($first)->signedToday);
        $this->assertTrue($service->getCheckInStatus($second)->signedToday);
        $this->assertFalse($service->getCheckInStatus($first)->signedToday);
        $this->assertTrue($service->getCheckInStatus($second)->signedToday);

        $this->assertOnlyInfoRequests($service, $this->requests, 2);
    }

    public function testFreshReadsAgainAndReplacesTheEntry(): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: false, totalSignDay: 11)),
            self::answer(self::infoData(isSign: true, totalSignDay: 12)),
        ]);
        $account = $this->createAccount();

        $this->assertFalse($service->getCheckInStatus($account)->signedToday);
        $fresh = $service->getCheckInStatus($account, fresh: true);
        $this->assertTrue($fresh->signedToday);
        $this->assertSame(12, $fresh->totalDays);
        $this->assertTrue($service->getCheckInStatus($account)->signedToday);

        $this->assertOnlyInfoRequests($service, $this->requests, 2);
        $this->assertTrue($this->cache->getItem('check_in_42')->get()['data']['is_sign']);
    }

    public function testErrorIsNotCached(): void
    {
        $service = $this->createService([
            self::answer(null, -100, 'Please login'),
            self::answer(self::infoData(isSign: true)),
        ]);
        $account = $this->createAccount();

        try {
            $service->getCheckInStatus($account);
            $this->fail('The error retcode should throw');
        } catch (RetrieveSignInfoException) {
        }
        $this->assertFalse($this->cache->getItem('check_in_42')->isHit());

        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertOnlyInfoRequests($service, $this->requests, 2);
    }

    public function testAnswerWithoutDataReadsAsNotSignedAndIsNotCached(): void
    {
        $service = $this->createService([self::answer(null), self::answer(self::infoData(isSign: true))]);
        $account = $this->createAccount();

        $status = $service->getCheckInStatus($account);
        $this->assertFalse($status->signedToday);
        $this->assertNull($status->totalDays);
        $this->assertFalse($this->cache->getItem('check_in_42')->isHit());

        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertOnlyInfoRequests($service, $this->requests, 2);
    }

    public function testAnswerWithoutRetcodeIsNotCached(): void
    {
        $service = $this->createService([['data' => self::infoData(isSign: true)], self::answer(self::infoData(isSign: true))]);
        $account = $this->createAccount();

        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertFalse($this->cache->getItem('check_in_42')->isHit());
        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertOnlyInfoRequests($service, $this->requests, 2);
    }

    public function testUnknownSignFlagReadsAsNotSigned(): void
    {
        $data = self::infoData(isSign: true);
        unset($data['is_sign']);
        $data['total_sign_day'] = '12';
        $service = $this->createService([self::answer($data)]);

        $status = $service->getCheckInStatus($this->createAccount());

        $this->assertFalse($status->signedToday);
        $this->assertNull($status->totalDays);
    }

    public function testProfileWithoutIdIsNeverCached(): void
    {
        $service = $this->createService([self::answer(self::infoData(isSign: false)), self::answer(self::infoData(isSign: true))]);
        $account = $this->createAccount(profileId: null);

        $this->assertFalse($service->getCheckInStatus($account)->signedToday);
        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertOnlyInfoRequests($service, $this->requests, 2);
    }

    public function testFetchedAtComesFromTheEntryInTheClockTimezone(): void
    {
        $fetchedAt = new \DateTimeImmutable('2026-11-22 09:55:00', new \DateTimeZone('UTC'));
        $this->cache->save($this->cache->getItem('check_in_42')->set([
            'fetchedAt' => $fetchedAt->getTimestamp(),
            'data' => self::infoData(isSign: true, totalSignDay: 5),
        ]));
        $service = $this->createService([], timezone: 'Europe/Rome');

        $status = $service->getCheckInStatus($this->createAccount());

        $this->assertSame([], $this->requests);
        $this->assertTrue($status->signedToday);
        $this->assertSame(5, $status->totalDays);
        $this->assertSame($fetchedAt->getTimestamp(), $status->fetchedAt->getTimestamp());
        $this->assertSame('Europe/Rome', $status->fetchedAt->getTimezone()->getName());
        $this->assertSame($status->fetchedAt, $status->getFetchedAt());
    }

    public function testClaimDeletesTheEntry(): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: false, totalSignDay: 11)), // status
            self::answer(self::infoData(isSign: false, totalSignDay: 11)), // check-in: info
            self::awardsAnswer(),                                          // check-in: home
            self::answer(['code' => 'ok']),                                // check-in: sign
            self::answer(self::infoData(isSign: true, totalSignDay: 12)),  // status again
        ]);
        $account = $this->createAccount();

        $this->assertFalse($service->getCheckInStatus($account)->signedToday);
        $this->assertTrue($this->cache->getItem('check_in_42')->isHit());

        $result = $service->checkIn($account);

        $this->assertFalse($result->isAlreadySignedIn());
        $this->assertSame(12, $result->getTotalSignDay());
        $this->assertFalse($this->cache->getItem('check_in_42')->isHit());
        $this->assertSame(['method' => 'POST', 'url' => $service->getUrlSign()], $this->requests[3]);

        $status = $service->getCheckInStatus($account);
        $this->assertTrue($status->signedToday);
        $this->assertSame(12, $status->totalDays);
        $this->assertCount(5, $this->requests);
    }

    public function testAlreadySignedCheckInDeletesTheEntry(): void
    {
        $this->cache->save($this->cache->getItem('check_in_42')->set([
            'fetchedAt' => (new \DateTimeImmutable(self::NOW))->getTimestamp(),
            'data' => self::infoData(isSign: false, totalSignDay: 11),
        ]));
        $service = $this->createService([
            self::answer(self::infoData(isSign: true, totalSignDay: 12)), // check-in: info
            self::awardsAnswer(),                                         // check-in: home
            self::answer(self::infoData(isSign: true, totalSignDay: 12)), // status
        ]);
        $account = $this->createAccount();

        $this->assertTrue($service->checkIn($account)->isAlreadySignedIn());
        $this->assertFalse($this->cache->getItem('check_in_42')->isHit());

        $this->assertTrue($service->getCheckInStatus($account)->signedToday);
        $this->assertCount(3, $this->requests);
        $this->assertNotContains('POST', array_column($this->requests, 'method'));
    }

    public function testFailedClaimKeepsTheEntry(): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: false, totalSignDay: 11)), // status
            self::answer(self::infoData(isSign: false, totalSignDay: 11)), // check-in: info
            self::awardsAnswer(),                                          // check-in: home
            self::answer(null, -10001, 'sign failed'),                     // check-in: sign
        ]);
        $account = $this->createAccount();
        $service->getCheckInStatus($account);

        try {
            $service->checkIn($account);
            $this->fail('The failed sign should throw');
        } catch (SignInFailedException) {
        }

        $this->assertTrue($this->cache->getItem('check_in_42')->isHit());
    }

    public function testStatusRejectsAnotherAccountType(): void
    {
        $service = $this->createService([]);

        $this->expectException(\InvalidArgumentException::class);
        $service->getCheckInStatus(new GryphlineRuntimeAccountData());
    }

    public function testContainerWiresTheCheckInStatusPool(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        foreach (self::gameServices() as [$class]) {
            $service = $container->get($class);
            $this->assertSame($container->get('hoyoverse.check_in_status.cache'), $service->getCheckInStatusCache(), $class);
            // HI3 and ToT have no notes clock: the check-in status has its own
            $this->assertNotNull((new \ReflectionProperty(GameService::class, 'checkInStatusClock'))->getValue($service), $class);
        }
    }
}

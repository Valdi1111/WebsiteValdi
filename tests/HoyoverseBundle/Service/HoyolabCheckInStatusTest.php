<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GryphlineBundle\Model\RuntimeAccountData as GryphlineRuntimeAccountData;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveSignInfoException;
use App\HoyoverseBundle\Exception\SignInFailedException;
use App\HoyoverseBundle\Exception\SignInRiskException;
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
 * Read-only, per-profile cached check-in status of the HoYoLAB check-in trait, with the application serializer, and
 * what a claim (also one blocked by a captcha) does to it.
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

    /**
     * Captcha (GeeTest) answers of the sign endpoint, as reported by the open-source HoYoLAB clients: retcode 0 and
     * "OK", but nothing claimed.
     *
     * @return iterable<string, array{array<string, mixed>, int|string|null}>
     */
    public static function riskSignData(): iterable
    {
        // Global endpoints: the flags are nested in gt_result
        yield 'gt_result, risk code 5001' => [
            ['code' => 'ok', 'first_bind' => false, 'gt_result' => ['risk_code' => 5001, 'gt' => 'a280f1e34s45a37ae91c65fc745fba71c', 'challenge' => 'ef74ab0042086a343ba869f5e29dab6e', 'success' => 1, 'is_risk' => true]],
            5001,
        ];
        yield 'gt_result, risk code 5003' => [
            ['code' => 'ok', 'first_bind' => false, 'gt_result' => ['risk_code' => 5003, 'gt' => 'a280f1e34s45a37ae91c65fc745fba71c', 'challenge' => '173a8894200a4b727007', 'success' => 1, 'is_risk' => true]],
            5003,
        ];
        yield 'gt_result, is_risk without risk code' => [
            ['code' => 'ok', 'gt_result' => ['gt' => 'a280f1e34s45a37ae91c65fc745fba71c', 'challenge' => 'ef74ab0042086a343ba869f5e29dab6e', 'success' => 1, 'is_risk' => true]],
            null,
        ];
        // Older flat shape, without is_risk
        yield 'flat data, risk code 375' => [
            ['code' => '', 'risk_code' => 375, 'gt' => 'f1342eaf43e356aa680d18f55fa1f1ca', 'challenge' => '0df673a17f95e318a0bdf943742e4a9f', 'success' => 1],
            375,
        ];
    }

    /**
     * @param array<string, mixed> $signData
     */
    #[DataProvider('riskSignData')]
    public function testCaptchaAnswerIsNotAClaimAndKeepsTheEntry(array $signData, int|string|null $riskCode): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: false, totalSignDay: 11)), // status
            self::answer(self::infoData(isSign: false, totalSignDay: 11)), // check-in: info
            self::awardsAnswer(),                                          // check-in: home
            self::answer($signData),                                       // check-in: sign, captcha
        ]);
        $account = $this->createAccount();
        $service->getCheckInStatus($account);

        try {
            $service->checkIn($account);
            $this->fail('A captcha answer must not read as a claim');
        } catch (SignInRiskException $e) {
            // Still a failed sign for the callers that only know SignInFailedException
            $this->assertInstanceOf(SignInFailedException::class, $e);
            $this->assertSame($riskCode, $e->getRiskCode());
            $this->assertSame(0, $e->getHoyolabRetcode());
            $this->assertSame($signData, $e->getHoyolabBody()['data']);
        }

        // One claim attempt only, and the status still reads "not signed" from its entry, without a new request
        $this->assertSame(['method' => 'POST', 'url' => $service->getUrlSign()], $this->requests[3]);
        $this->assertCount(1, array_filter($this->requests, static fn (array $request): bool => $request['method'] === 'POST'));
        $status = $service->getCheckInStatus($account);
        $this->assertFalse($status->signedToday);
        $this->assertSame(11, $status->totalDays);
        $this->assertCount(4, $this->requests);
    }

    #[DataProvider('gameServices')]
    public function testCaptchaAnswerIsNotAClaimForEveryGame(string $class): void
    {
        [$signData] = self::riskSignData()->current();
        $service = $this->createService([
            self::answer(self::infoData(isSign: false, totalSignDay: 11)), // check-in: info
            self::awardsAnswer(),                                          // check-in: home
            self::answer($signData),                                       // check-in: sign, captcha
        ], $class);

        $this->expectException(SignInRiskException::class);
        $service->checkIn($this->createAccount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function claimedSignData(): iterable
    {
        // What a claim answers on the global endpoints: the flags are there, all clear
        yield 'gt_result without risk' => [
            ['code' => 'ok', 'first_bind' => false, 'gt_result' => ['risk_code' => 0, 'gt' => '', 'challenge' => '', 'success' => 0, 'is_risk' => false]],
        ];
        yield 'no flags' => [['code' => 'ok']];
    }

    /**
     * @param array<string, mixed> $signData
     */
    #[DataProvider('claimedSignData')]
    public function testSignAnswerWithoutRiskIsAClaim(array $signData): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: false, totalSignDay: 11)), // check-in: info
            self::awardsAnswer(),                                          // check-in: home
            self::answer($signData),                                       // check-in: sign
        ]);

        $result = $service->checkIn($this->createAccount());

        $this->assertFalse($result->isAlreadySignedIn());
        $this->assertSame($service->getCheckInSuccessMessage(), $result->getResult());
        $this->assertSame(12, $result->getTotalSignDay());
        $this->assertSame('Reward 12', $result->getAwardData()->getName());
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

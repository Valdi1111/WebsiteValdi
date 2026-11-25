<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\GamesBundle\Model\Game\CheckInResult;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveSignInfoException;
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
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Check-in of the HoYoLAB check-in trait against unusual or partial HoYoLAB answers, with the application serializer.
 */
class HoyolabCheckInTest extends KernelTestCase
{
    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

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
     * @param bool                       $fromContainer the service as the container wires it, only the client mocked
     */
    private function createService(array $bodies, string $class = GenshinImpactService::class, bool $fromContainer = false): GameService
    {
        self::bootKernel();

        if ($fromContainer) {
            $service = static::getContainer()->get($class);
        } else {
            $service = new $class(new NullLogger());
            $service->setDenormalizer(static::getContainer()->get('serializer'));
        }
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url) use (&$bodies): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => strtok($url, '?')];
            $body = array_shift($bodies) ?? ['retcode' => -1, 'message' => 'unexpected request'];

            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        }));

        return $service;
    }

    private function createAccount(): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())
            ->setGameId(GenshinImpactService::GAME_ID)
            ->setGameUid('700000001')
            ->setRegion('os_euro')
            ->setNickname('Traveler');

        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile($profile);
    }

    /**
     * @return array<string, mixed> a complete check-in info payload
     */
    private static function infoData(bool $isSign = false, int $totalSignDay = 11): array
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
     * @return array<string, mixed> the home answer with one award per day, "Reward N" for day N
     */
    private static function awardsAnswer(int $days = 30): array
    {
        $awards = [];
        for ($day = 1; $day <= $days; ++$day) {
            $awards[] = ['icon' => "https://example.test/{$day}.png", 'name' => "Reward {$day}", 'cnt' => $day];
        }

        return self::answer(['month' => 11, 'awards' => $awards]);
    }

    /**
     * Runs the check-in with PHP warnings and notices turned into exceptions: an out-of-range award lookup must not
     * even warn ("Undefined array key"), PHPUnit alone would only report it.
     */
    private function checkIn(GameService $service): CheckInResult
    {
        // Deprecations are left out: the mask holds warnings and notices only
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
        try {
            return $service->checkIn($this->createAccount());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return list<string>
     */
    private function requestedMethods(): array
    {
        return array_column($this->requests, 'method');
    }

    #[DataProvider('gameServices')]
    public function testClaimReturnsTheAwardOfTheNextDay(string $class): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: false, totalSignDay: 11)),
            self::awardsAnswer(),
            self::answer(['code' => 'ok']),
        ], $class);

        $result = $this->checkIn($service);

        $this->assertFalse($result->isAlreadySignedIn());
        $this->assertSame($service->getCheckInSuccessMessage(), $result->getResult());
        $this->assertSame(12, $result->getTotalSignDay());
        $this->assertSame('Reward 12', $result->getAwardData()?->getName());
        $this->assertSame(['method' => 'POST', 'url' => $service->getUrlSign()], $this->requests[2]);
    }

    #[DataProvider('gameServices')]
    public function testAlreadySignedReturnsTheAwardOfTheLastClaimedDay(string $class): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: true, totalSignDay: 12)),
            self::awardsAnswer(),
        ], $class);

        $result = $this->checkIn($service);

        $this->assertTrue($result->isAlreadySignedIn());
        $this->assertSame($service->getCheckInSignedMessage(), $result->getResult());
        $this->assertSame(12, $result->getTotalSignDay());
        $this->assertSame('Reward 12', $result->getAwardData()?->getName());
        $this->assertNotContains('POST', $this->requestedMethods());
    }

    public function testAlreadySignedWithoutSignedDaysHasNoAward(): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: true, totalSignDay: 0)),
            self::awardsAnswer(),
        ]);

        $result = $this->checkIn($service);

        $this->assertTrue($result->isAlreadySignedIn());
        $this->assertSame(0, $result->getTotalSignDay());
        $this->assertNull($result->getAwardData());
        $this->assertNotContains('POST', $this->requestedMethods());
    }

    public function testAlreadySignedBeyondTheAwardListHasNoAward(): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: true, totalSignDay: 5)),
            self::awardsAnswer(days: 3),
        ]);

        $result = $this->checkIn($service);

        $this->assertTrue($result->isAlreadySignedIn());
        $this->assertSame(5, $result->getTotalSignDay());
        $this->assertNull($result->getAwardData());
    }

    public function testClaimBeyondTheAwardListStillReportsTheClaim(): void
    {
        $service = $this->createService([
            self::answer(self::infoData(isSign: false, totalSignDay: 3)),
            self::awardsAnswer(days: 3),
            self::answer(['code' => 'ok']),
        ]);

        // Nothing may throw after the claim: a retried message would claim again
        $result = $this->checkIn($service);

        $this->assertFalse($result->isAlreadySignedIn());
        $this->assertSame($service->getCheckInSuccessMessage(), $result->getResult());
        $this->assertSame(4, $result->getTotalSignDay());
        $this->assertNull($result->getAwardData());
        $this->assertSame(['method' => 'POST', 'url' => $service->getUrlSign()], $this->requests[2]);
    }

    public function testCompleteInfoKeepsEveryField(): void
    {
        $service = $this->createService([self::answer(self::infoData(isSign: true, totalSignDay: 12))]);

        $signInfo = $service->getSignInfo($this->createAccount());

        $this->assertTrue($signInfo->isSign());
        $this->assertSame(12, $signInfo->getTotalSignDay());
        $this->assertSame('2026-11-22', $signInfo->getToday()?->format('Y-m-d'));
        $this->assertFalse($signInfo->isFirstBind());
        $this->assertFalse($signInfo->isSub());
        $this->assertSame('', $signInfo->getRegion());
        $this->assertFalse($signInfo->isMonthLastDay());
    }

    public function testDateIsReadWithAnExplicitFormat(): void
    {
        $service = $this->createService([self::answer(self::infoData())]);
        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $today = $service->getSignInfo($this->createAccount())->getToday();
        } finally {
            restore_error_handler();
        }

        $this->assertSame('2026-11-22 00:00:00', $today?->format('Y-m-d H:i:s'));
        // Symfony 8.1 deprecates the loose DateTime parsing of "2026-11-22" (an exception from 9.0)
        $this->assertSame([], array_values(array_filter(
            $deprecations,
            static fn (string $message): bool => str_contains($message, 'DateTime')
        )));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function partialInfo(): iterable
    {
        yield 'only the sign state' => [['total_sign_day' => 4, 'is_sign' => false]];
        yield 'null optional fields' => [[
            'total_sign_day' => 4,
            'today' => null,
            'is_sign' => false,
            'first_bind' => null,
            'is_sub' => null,
            'region' => null,
            'month_last_day' => null,
        ]];
        yield 'malformed optional fields' => [[
            'total_sign_day' => 4,
            'today' => '',
            'is_sign' => false,
            'first_bind' => 'no',
            'is_sub' => [],
            'region' => 7,
            'month_last_day' => 'false',
        ]];
        yield 'unparsable date and extra fields' => [[
            'total_sign_day' => 4,
            'today' => 'not a date',
            'is_sign' => false,
            'sign_cnt_missed' => 3,
            'short_sign_day' => 0,
        ]];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('partialInfo')]
    public function testPartialInfoLeavesTheOptionalFieldsUnknown(array $data): void
    {
        $service = $this->createService([self::answer($data)]);

        $signInfo = $service->getSignInfo($this->createAccount());

        $this->assertFalse($signInfo->isSign());
        $this->assertSame(4, $signInfo->getTotalSignDay());
        $this->assertNull($signInfo->getToday());
        $this->assertNull($signInfo->isFirstBind());
        $this->assertNull($signInfo->isSub());
        $this->assertNull($signInfo->getRegion());
        $this->assertNull($signInfo->isMonthLastDay());
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('partialInfo')]
    public function testPartialInfoStillChecksIn(array $data): void
    {
        $service = $this->createService([self::answer($data), self::awardsAnswer(), self::answer(['code' => 'ok'])]);

        $result = $this->checkIn($service);

        $this->assertFalse($result->isAlreadySignedIn());
        $this->assertSame(5, $result->getTotalSignDay());
        $this->assertSame('Reward 5', $result->getAwardData()?->getName());
        $this->assertSame(['GET', 'GET', 'POST'], $this->requestedMethods());
    }

    public function testContainerWiredServiceToleratesMalformedOptionalFields(): void
    {
        $data = ['total_sign_day' => 4, 'today' => '', 'is_sign' => false, 'region' => 7, 'first_bind' => 'no'];
        $service = $this->createService([self::answer($data), self::awardsAnswer(), self::answer(['code' => 'ok'])], fromContainer: true);

        $result = $this->checkIn($service);

        $this->assertSame(5, $result->getTotalSignDay());
        $this->assertSame('Reward 5', $result->getAwardData()?->getName());
        $this->assertSame(['GET', 'GET', 'POST'], $this->requestedMethods());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function infoWithoutSignState(): iterable
    {
        $withoutIsSign = self::infoData();
        unset($withoutIsSign['is_sign']);
        $withoutTotal = self::infoData();
        unset($withoutTotal['total_sign_day']);

        yield 'no data' => [null];
        yield 'empty data' => [[]];
        yield 'no is_sign' => [$withoutIsSign];
        yield 'no total_sign_day' => [$withoutTotal];
        yield 'null is_sign' => [['is_sign' => null] + self::infoData()];
        yield 'string is_sign' => [['is_sign' => 'false'] + self::infoData()];
        yield 'null total_sign_day' => [['total_sign_day' => null] + self::infoData()];
        yield 'string total_sign_day' => [['total_sign_day' => '11'] + self::infoData()];
    }

    /**
     * An unknown sign state is never read as "not signed": no claim is attempted on a guess.
     *
     * @param array<string, mixed>|null $data
     */
    #[DataProvider('infoWithoutSignState')]
    public function testInfoWithoutSignStateFailsBeforeAnyClaim(?array $data): void
    {
        $service = $this->createService([self::answer($data), self::awardsAnswer(), self::answer(['code' => 'ok'])]);

        try {
            $this->checkIn($service);
            $this->fail('An info answer without a usable sign state should throw');
        } catch (RetrieveSignInfoException $e) {
            $this->assertSame(self::answer($data), $e->getHoyolabBody());
        }

        $this->assertSame(['GET'], $this->requestedMethods());
    }
}

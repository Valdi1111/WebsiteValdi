<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\GameService;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fetch time of the HoYoLAB notes and the instants built from it: relative times of a cached payload are counted
 * from when it was fetched, not from when it is read.
 */
class NotesFetchTimeTest extends KernelTestCase
{
    private const string NOW = '2026-11-22 10:00:00';
    /** Age of the cached entry in the tests that read one */
    private const int AGE = 240;

    private ArrayAdapter $cache;

    private int $requests = 0;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(300);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    /**
     * @template S of GameService
     * @param class-string<S> $class
     * @param array<string, mixed>|null $answer HoYoLAB data of the only request allowed, null for none
     * @return S
     */
    private function createService(string $class, ?array $answer = null): GameService
    {
        self::bootKernel();

        $service = new $class(new NullLogger());
        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setHoyolabUtils(new HoyolabUtilsService());
        $service->setNotesCache($this->cache);
        $service->setNotesClock(new MockClock(self::NOW, 'UTC'));
        $service->setHoyolabClient(new MockHttpClient(function () use (&$answer): MockResponse {
            $this->requests++;
            $body = $answer !== null ? ['retcode' => 0, 'message' => 'OK', 'data' => $answer] : ['retcode' => -1, 'message' => 'unexpected request'];
            $answer = null;

            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        }));

        return $service;
    }

    private function createAccount(int $gameId): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())->setGameId($gameId)->setGameUid('700000001')->setRegion('os_euro');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 42);

        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile($profile);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function cacheEntryFetchedBefore(array $data, int $seconds = self::AGE): void
    {
        $this->cache->get('notes_42', static fn (): array => ['fetchedAt' => self::now()->getTimestamp() - $seconds, 'data' => $data]);
    }

    public function testNewNotesAreFetchedNow(): void
    {
        $service = $this->createService(GenshinImpactService::class, ['current_resin' => 100, 'max_resin' => 200, 'resin_recovery_time' => '48000']);

        $notes = $service->getNotes($this->createAccount(GenshinImpactService::GAME_ID));

        $this->assertEquals(self::now(), $notes->getFetchedAt());
        $this->assertSame(1, $this->requests);
    }

    public function testCachedNotesKeepTheirFetchTime(): void
    {
        $this->cacheEntryFetchedBefore(['current_resin' => 100]);
        $service = $this->createService(GenshinImpactService::class);

        $notes = $service->getNotes($this->createAccount(GenshinImpactService::GAME_ID));

        $this->assertEquals(self::now()->modify('-' . self::AGE . ' seconds'), $notes->getFetchedAt());
        $this->assertSame(0, $this->requests);
    }

    public function testGenshinImpactInstantsCountFromTheFetch(): void
    {
        $this->cacheEntryFetchedBefore([
            'current_resin' => 100,
            'max_resin' => 200,
            'resin_recovery_time' => '48000',
            'current_home_coin' => 1000,
            'max_home_coin' => 2400,
            'home_coin_recovery_time' => '36000',
            'expeditions' => [
                ['avatar_side_icon' => 'a.png', 'status' => 'Ongoing', 'remained_time' => '3600'],
                ['avatar_side_icon' => 'b.png', 'status' => 'Ongoing', 'remained_time' => '7200'],
                ['avatar_side_icon' => 'c.png', 'status' => 'Finished', 'remained_time' => '0'],
            ],
        ]);
        $service = $this->createService(GenshinImpactService::class);
        $account = $this->createAccount(GenshinImpactService::GAME_ID);

        $stamina = $service->getStaminaData($account);
        $this->assertSame(48000, $stamina->getStaminaRecoveryTime(), 'the relative time stays as sent');
        $this->assertSame(48000 - self::AGE, $stamina->getSecondsUntilFull(self::now()));

        $realm = $service->getRealmData($account);
        $this->assertSame(36000 - self::AGE, $realm->getSecondsUntilFull(self::now()));

        $expeditions = $service->getExpeditionsData($account);
        $this->assertSame(7200, $expeditions->getCompletionTime());
        $this->assertSame(7200 - self::AGE, $expeditions->getSecondsUntilAllBack(self::now()));
        $this->assertSame(0, $this->requests);
    }

    public function testHonkaiStarRailInstantsCountFromTheFetch(): void
    {
        $this->cacheEntryFetchedBefore([
            'current_stamina' => 200,
            'max_stamina' => 300,
            'stamina_recover_time' => 36000,
            'expeditions' => [
                ['avatars' => ['a.png'], 'status' => 'Ongoing', 'remaining_time' => 5000],
            ],
        ]);
        $service = $this->createService(HonkaiStarRailService::class);
        $account = $this->createAccount(HonkaiStarRailService::GAME_ID);

        $this->assertSame(36000 - self::AGE, $service->getStaminaData($account)->getSecondsUntilFull(self::now()));
        $this->assertSame(5000 - self::AGE, $service->getExpeditionsData($account)->getSecondsUntilAllBack(self::now()));
    }

    public function testZenlessZoneZeroInstantsCountFromTheFetch(): void
    {
        $this->cacheEntryFetchedBefore(['energy' => ['progress' => ['current' => 100, 'max' => 240], 'restore' => 50400]]);
        $service = $this->createService(ZenlessZoneZeroService::class);

        $stamina = $service->getStaminaData($this->createAccount(ZenlessZoneZeroService::GAME_ID));

        $this->assertSame(50400 - self::AGE, $stamina->getSecondsUntilFull(self::now()));
    }

    public function testFullStaminaIsFullAtTheFetch(): void
    {
        $this->cacheEntryFetchedBefore(['current_resin' => 200, 'max_resin' => 200, 'resin_recovery_time' => '0']);
        $service = $this->createService(GenshinImpactService::class);

        $stamina = $service->getStaminaData($this->createAccount(GenshinImpactService::GAME_ID));

        $this->assertEquals(self::now()->modify('-' . self::AGE . ' seconds'), $stamina->getFullAt());
        $this->assertSame(0, $stamina->getSecondsUntilFull(self::now()));
    }

    public function testUnknownRecoveryTimeHasNoInstant(): void
    {
        $this->cacheEntryFetchedBefore(['current_resin' => 100, 'max_resin' => 200]);
        $service = $this->createService(GenshinImpactService::class);

        $stamina = $service->getStaminaData($this->createAccount(GenshinImpactService::GAME_ID));

        $this->assertNull($stamina->getFullAt());
        $this->assertNull($stamina->getSecondsUntilFull(self::now()));
    }
}

<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveNotesDataException;
use App\HoyoverseBundle\Model\Notes\GenshinImpactNotes;
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
 * Per-profile notes cache of the HoYoLAB NotesTrait, with the application serializer.
 */
class GameNotesCacheTest extends KernelTestCase
{
    // Needed by CredentialNormalizer, built with the serializer; the test environment has no value for it
    private const string PASSWORDS_KEY_ENV = 'PASSWORDS_ENCRYPTION_KEY';

    private const string NOW = '2026-11-22 10:00:00';

    /** @var array{server: mixed, env: mixed} */
    private array $previous;

    /** @var list<array{role_id: string}> */
    private array $requests = [];

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->previous = ['server' => $_SERVER[self::PASSWORDS_KEY_ENV] ?? null, 'env' => $_ENV[self::PASSWORDS_KEY_ENV] ?? null];
        $_SERVER[self::PASSWORDS_KEY_ENV] ??= 'test-only-key';
        $_ENV[self::PASSWORDS_KEY_ENV] ??= 'test-only-key';

        $this->cache = new ArrayAdapter(300);
    }

    protected function tearDown(): void
    {
        // Shut the kernel down first, then give the environment back
        parent::tearDown();

        if ($this->previous['server'] === null) {
            unset($_SERVER[self::PASSWORDS_KEY_ENV]);
        } else {
            $_SERVER[self::PASSWORDS_KEY_ENV] = $this->previous['server'];
        }

        if ($this->previous['env'] === null) {
            unset($_ENV[self::PASSWORDS_KEY_ENV]);
        } else {
            $_ENV[self::PASSWORDS_KEY_ENV] = $this->previous['env'];
        }
    }

    /**
     * @param list<array<string, mixed>> $bodies HoYoLAB answers, one per request in order
     */
    private function createService(array $bodies): GenshinImpactService
    {
        self::bootKernel();

        $service = new GenshinImpactService(new NullLogger());
        $service->setDenormalizer(static::getContainer()->get('serializer'));
        $service->setHoyolabUtils(new HoyolabUtilsService());
        $service->setNotesCache($this->cache);
        $service->setNotesClock(new MockClock(self::NOW, 'UTC'));
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url) use (&$bodies): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->requests[] = ['role_id' => (string) ($query['role_id'] ?? '')];
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
    private static function notesData(int $resin = 120): array
    {
        return [
            'current_resin' => $resin,
            'max_resin' => 200,
            'resin_recovery_time' => '38400',
            'finished_task_num' => 3,
            'total_task_num' => 4,
            'remain_resin_discount_num' => 1,
            'resin_discount_num_limit' => 3,
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

    public function testDataGettersShareOneRequest(): void
    {
        $service = $this->createService([self::answer(self::notesData())]);
        $account = $this->createAccount();

        $stamina = $service->getStaminaData($account);
        $dailies = $service->getDailiesData($account);
        $weeklies = $service->getWeekliesData($account);

        $this->assertCount(1, $this->requests);
        $this->assertSame(120, $stamina->getCurrentStamina());
        $this->assertSame(3, $dailies->first()->getCurrentValue());
        $this->assertSame(1, $weeklies->first()->getCurrentValue());
    }

    public function testEntryHoldsTheRawPayloadAndTheFetchTime(): void
    {
        $service = $this->createService([self::answer(self::notesData())]);

        $notes = $service->getNotes($this->createAccount());

        $this->assertInstanceOf(GenshinImpactNotes::class, $notes);
        $item = $this->cache->getItem('notes_42');
        $this->assertTrue($item->isHit());
        $this->assertSame(
            ['fetchedAt' => (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->getTimestamp(), 'data' => self::notesData()],
            $item->get()
        );
    }

    public function testEachProfileHasItsOwnEntry(): void
    {
        $service = $this->createService([self::answer(self::notesData(10)), self::answer(self::notesData(20))]);
        $first = $this->createAccount(1, '700000001');
        $second = $this->createAccount(2, '700000002');

        $this->assertSame(10, $service->getNotes($first)->getCurrentResin());
        $this->assertSame(20, $service->getNotes($second)->getCurrentResin());
        $this->assertSame(10, $service->getNotes($first)->getCurrentResin());
        $this->assertSame(20, $service->getNotes($second)->getCurrentResin());

        $this->assertSame([['role_id' => '700000001'], ['role_id' => '700000002']], $this->requests);
    }

    public function testFreshCallsTheApiAndReplacesTheEntry(): void
    {
        $service = $this->createService([self::answer(self::notesData(10)), self::answer(self::notesData(50))]);
        $account = $this->createAccount();

        $this->assertSame(10, $service->getNotes($account)->getCurrentResin());
        $this->assertSame(50, $service->getNotes($account, fresh: true)->getCurrentResin());
        $this->assertSame(50, $service->getNotes($account)->getCurrentResin());

        $this->assertCount(2, $this->requests);
        $this->assertSame(50, $this->cache->getItem('notes_42')->get()['data']['current_resin']);
    }

    public function testSoftFailureIsReturnedButNotCached(): void
    {
        $service = $this->createService([
            self::answer(['current_resin' => 30], -501000, 'partial'),
            self::answer(self::notesData(40)),
        ]);
        $account = $this->createAccount();

        $this->assertSame(30, $service->getNotes($account)->getCurrentResin());
        $this->assertFalse($this->cache->getItem('notes_42')->isHit());

        $this->assertSame(40, $service->getNotes($account)->getCurrentResin());
        $this->assertCount(2, $this->requests);
    }

    public function testAnswerWithoutDataIsNotCached(): void
    {
        $service = $this->createService([self::answer(null), self::answer(self::notesData(40))]);
        $account = $this->createAccount();

        $this->assertNull($service->getNotes($account)->getCurrentResin());
        $this->assertFalse($this->cache->getItem('notes_42')->isHit());
        $this->assertSame(40, $service->getNotes($account)->getCurrentResin());
    }

    public function testErrorIsNotCached(): void
    {
        $service = $this->createService([
            self::answer(null, 10001, 'Please login'),
            self::answer(self::notesData(40)),
        ]);
        $account = $this->createAccount();

        try {
            $service->getNotes($account);
            $this->fail('The error retcode should throw');
        } catch (RetrieveNotesDataException) {
        }
        $this->assertFalse($this->cache->getItem('notes_42')->isHit());

        $this->assertSame(40, $service->getNotes($account)->getCurrentResin());
        $this->assertCount(2, $this->requests);
    }

    public function testProfileWithoutIdIsNeverCached(): void
    {
        $service = $this->createService([self::answer(self::notesData(10)), self::answer(self::notesData(20))]);
        $account = $this->createAccount(profileId: null);

        $this->assertSame(10, $service->getNotes($account)->getCurrentResin());
        $this->assertSame(20, $service->getNotes($account)->getCurrentResin());
        $this->assertCount(2, $this->requests);
    }

    public function testEntryOfAnotherShapeIsFetchedAgain(): void
    {
        $item = $this->cache->getItem('notes_42');
        $this->cache->save($item->set(self::notesData(10)));
        $service = $this->createService([self::answer(self::notesData(70))]);

        $this->assertSame(70, $service->getNotes($this->createAccount())->getCurrentResin());
        $this->assertCount(1, $this->requests);
        $this->assertSame(70, $this->cache->getItem('notes_42')->get()['data']['current_resin']);
    }

    public function testContainerWiresTheNotesPool(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        foreach ([GenshinImpactService::class, HonkaiStarRailService::class, ZenlessZoneZeroService::class] as $class) {
            $this->assertSame($container->get('hoyoverse.notes.cache'), $container->get($class)->getNotesCache(), $class);
        }
    }
}

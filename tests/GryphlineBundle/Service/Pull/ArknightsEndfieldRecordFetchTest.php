<?php

namespace App\Tests\GryphlineBundle\Service\Pull;

use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Entity\GryphlinePull;
use App\GryphlineBundle\Exception\SkportApiException;
use App\GryphlineBundle\Model\Pull\GryphlinePullItem;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Catalog\AkeCatalog;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Headhunting record reads of Arknights: Endfield (GryphlinePullTrait) against a fake ef-webview record API,
 * with the application serializer and object mapper.
 */
class ArknightsEndfieldRecordFetchTest extends KernelTestCase
{
    private const string HOST = 'ef-webview.gryphline.com';
    private const string CHAR_PATH = '/api/record/char';
    private const string META_PATH = '/api/record/char/meta';
    private const string WEAPON_PATH = '/api/record/weapon';
    private const string TOKEN = 'u8-token-test';

    private const string CHARTERED = 'E_CharacterGachaPoolType_Special';
    private const string RERUN = 'E_CharacterGachaPoolType_Rerun';
    private const string JOINT = 'E_CharacterGachaPoolType_Joint';
    private const string STANDARD = 'E_CharacterGachaPoolType_Standard';
    private const string BEGINNER = 'E_CharacterGachaPoolType_Beginner';
    private const string WEAPON = 'E_WeaponGachaPoolType_Standard';
    private const string WEAPON_RERUN = 'E_WeaponGachaPoolType_Rerun';

    /** @var list<array{method: string, url: string}> requests received by the fake record API */
    private array $requests = [];

    /** @var list<array{level: string, message: string}> */
    private array $logs = [];

    /**
     * @param list<MockResponse> $responses record API answers, one per request in order
     */
    private function createService(array $responses = [], ?AkeCatalog $catalog = null, bool $realWiring = true): ArknightsEndfieldService
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url];

            return array_shift($responses) ?? self::answer(['code' => -1, 'msg' => 'unexpected request']);
        });

        if ($realWiring) {
            self::bootKernel();
            $denormalizer = static::getContainer()->get('serializer');
            $objectMapper = static::getContainer()->get(ObjectMapperInterface::class);
        } else {
            $denormalizer = $this->createStub(DenormalizerInterface::class);
            $objectMapper = $this->createStub(ObjectMapperInterface::class);
        }

        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /**
             * @param list<array{level: string, message: string}> $logs
             */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        return new ArknightsEndfieldService(
            // SKPort client: the record API goes through the plain HTTP client
            $this->createStub(HttpClientInterface::class),
            $client,
            $this->createStub(SkportSignerService::class),
            $this->createStub(SkportAuthService::class),
            $this->createStub(SkportUtilsService::class),
            $denormalizer,
            $objectMapper,
            $logger,
            $catalog,
        );
    }

    private function createProfile(): GryphlineGameProfile
    {
        $profile = (new GryphlineGameProfile())
            ->setGameId(ArknightsEndfieldService::GAME_ID)
            ->setGameBiz(ArknightsEndfieldService::GAME_BIZ)
            ->setGameUid('4000000001')
            ->setServerId('2')
            ->setNickname('Endministrator');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, 12);

        return $profile;
    }

    /**
     * Stored AkeData catalog with the weapon pools the weapon tests read.
     */
    private static function createCatalog(): AkeCatalog
    {
        $pool = static fn (string $id, string $category, int $type): array => ['id' => $id, 'category' => $category, 'type' => $type, 'up' => []];

        $catalog = new AkeCatalog(new ArrayAdapter());
        $catalog->save([
            'version' => '1.6.4@11944529-13',
            'built_at' => '2026-11-23T06:00:00+01:00',
            'items' => [
                'wpn_funnel_0001' => ['id' => 'wpn_funnel_0001', 'name' => 'Hypernova Auto', 'rarity' => 4, 'type' => 'weapon'],
            ],
            'pools' => [
                'weaponbox_1_6_1' => $pool('weaponbox_1_6_1', AkeCatalog::TYPE_WEAPON, AkeCatalog::WEAPON_POOL_TYPE_NORMAL),
                'weaponbox_rerun_1_6_1' => $pool('weaponbox_rerun_1_6_1', AkeCatalog::TYPE_WEAPON, AkeCatalog::WEAPON_POOL_TYPE_RERUN),
                'weaponbox_1_4_1' => $pool('weaponbox_1_4_1', AkeCatalog::TYPE_WEAPON, AkeCatalog::WEAPON_POOL_TYPE_RERUN),
                'weaponbox_1_5_1' => $pool('weaponbox_1_5_1', AkeCatalog::TYPE_WEAPON, AkeCatalog::WEAPON_POOL_TYPE_NORMAL),
                'weaponbox_constant_1' => $pool('weaponbox_constant_1', AkeCatalog::TYPE_WEAPON, AkeCatalog::WEAPON_POOL_TYPE_NORMAL),
                'joint_1_2_2' => $pool('joint_1_2_2', AkeCatalog::TYPE_CHAR, AkeCatalog::CHAR_POOL_TYPE_JOINT),
            ],
        ]);
        $catalog->reset();

        return $catalog;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private static function page(array $list, bool $hasMore): MockResponse
    {
        return self::answer(['code' => 0, 'msg' => '', 'data' => ['list' => $list, 'hasMore' => $hasMore]]);
    }

    /**
     * Operator draw, as the record API sends it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function operatorDraw(array $overrides = []): array
    {
        return array_replace([
            'kind' => 'draw',
            'poolId' => 'special_1_5_1',
            'poolName' => 'Winter Hunt',
            'poolVersion' => null,
            'nameText' => 'Fluorite',
            'charId' => 'chr_0022_bounda',
            'charName' => 'Fluorite',
            'rarity' => 4,
            'isFree' => false,
            'isNew' => false,
            'gachaTs' => '1788361035823',
            'seqId' => '1049',
        ], $overrides);
    }

    /**
     * Bonus entry (no item, no rarity), as the record API sends it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function bonusEntry(array $overrides = []): array
    {
        return array_replace([
            'kind' => 'gift_intel_book',
            'poolId' => 'special_1_5_1',
            'poolName' => 'Winter Hunt',
            'poolVersion' => null,
            'nameText' => 'Headhunting Dossier',
            'gachaTs' => '1788360846227',
            'seqId' => '1047',
        ], $overrides);
    }

    /**
     * Weapon draw, as the record API sends it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function weaponDraw(array $overrides = []): array
    {
        return array_replace([
            'kind' => 'draw',
            'isNew' => false,
            'seqId' => '540',
            'poolId' => 'weaponbox_1_6_1',
            'rarity' => 4,
            'gachaTs' => '1792073868904',
            'nameText' => 'Hypernova Auto',
            'poolName' => 'Seal of Jade Issue',
            'poolType' => 'normal',
            'weaponId' => 'wpn_funnel_0001',
            'weaponName' => 'Hypernova Auto',
            'weaponType' => 'E_WeaponType_Wand',
            'poolVersion' => null,
        ], $overrides);
    }

    /**
     * @return array{path: string, query: array<string, string>}
     */
    private function request(int $index): array
    {
        $this->assertArrayHasKey($index, $this->requests, sprintf('Request #%d was not sent.', $index));
        $this->assertSame('GET', $this->requests[$index]['method']);

        $parts = parse_url($this->requests[$index]['url']);
        $this->assertSame('https', $parts['scheme'] ?? null);
        $this->assertSame(self::HOST, $parts['host'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        ksort($query);

        return ['path' => $parts['path'] ?? '', 'query' => $query];
    }

    /**
     * @param array<string, string> $expected
     * @param array<string, string> $actual
     */
    private static function assertQuery(array $expected, array $actual): void
    {
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    /**
     * @return list<string>
     */
    private function warnings(): array
    {
        return array_values(array_map(
            static fn (array $log): string => $log['message'],
            array_filter($this->logs, static fn (array $log): bool => $log['level'] === 'warning'),
        ));
    }

    /**
     * @param list<GryphlinePull> $pulls
     *
     * @return array<string, GryphlinePull> seq id => pull
     */
    private static function bySeqId(array $pulls): array
    {
        $indexed = [];
        foreach ($pulls as $pull) {
            $indexed[(string) $pull->getSeqId()] = $pull;
        }

        return $indexed;
    }

    public function testOperatorPageKeepsDrawsAndBonusEntries(): void
    {
        $draw = self::operatorDraw();
        $freeDraw = self::operatorDraw([
            'seqId' => '1048',
            'nameText' => 'Laevatain',
            'charId' => 'chr_0016_laevat',
            'charName' => 'Laevatain',
            'rarity' => 6,
            'isFree' => true,
            'poolVersion' => 2,
            'gachaTs' => '1788360900000',
        ]);
        $dossier = self::bonusEntry();
        // A record without a seq id cannot be stored (nor paginated on)
        $noSeqId = self::operatorDraw(['seqId' => null, 'nameText' => 'Ghost']);
        $token = self::bonusEntry(['kind' => 'gift_operator_token', 'nameText' => 'Token Tribute', 'poolVersion' => 0, 'seqId' => '1046', 'gachaTs' => '1788360846000']);

        $profile = $this->createProfile();
        $service = $this->createService([self::page([$draw, $freeDraw, $dossier, $noSeqId, $token], true)]);

        $nextSeqId = 'stale';
        $pulls = $service->fetchPullsPage($profile, self::CHARTERED, self::TOKEN, '1050', $nextSeqId);

        $this->assertCount(1, $this->requests);
        $request = $this->request(0);
        $this->assertSame(self::CHAR_PATH, $request['path']);
        self::assertQuery([
            'server_id' => '2',
            'lang' => 'en-us',
            'token' => self::TOKEN,
            'pool_type' => self::CHARTERED,
            'seq_id' => '1050',
        ], $request['query']);

        // The API has more records: the next page starts after the last record of this one
        $this->assertSame('1046', $nextSeqId);
        $this->assertCount(4, $pulls);
        $this->assertContainsOnlyInstancesOf(GryphlinePull::class, $pulls);
        $pulls = self::bySeqId($pulls);
        $this->assertSame(['1049', '1048', '1047', '1046'], array_map('strval', array_keys($pulls)));

        $fluorite = $pulls['1049'];
        $this->assertSame($profile, $fluorite->getGameProfile());
        $this->assertSame(GryphlinePull::KIND_DRAW, $fluorite->getKind());
        $this->assertTrue($fluorite->isDraw());
        $this->assertSame('Fluorite', $fluorite->getName());
        $this->assertSame('chr_0022_bounda', $fluorite->getItemId());
        $this->assertSame(4, $fluorite->getRarity());
        $this->assertFalse($fluorite->isFree());
        $this->assertNull($fluorite->getPoolVersion());
        $this->assertSame('special_1_5_1', $fluorite->getPoolId());
        $this->assertSame('Winter Hunt', $fluorite->getPoolName());
        $this->assertSame(self::CHARTERED, $fluorite->getPoolType());
        $this->assertSame('char', $fluorite->getPoolCategory());
        $this->assertSame('1788361035823', $fluorite->getGachaTs());
        $this->assertSame(1788361035, $fluorite->getPulledAt()?->getTimestamp());
        $this->assertSame($draw, $fluorite->getRawPayload());

        $laevatain = $pulls['1048'];
        $this->assertSame(GryphlinePull::KIND_DRAW, $laevatain->getKind());
        $this->assertSame('Laevatain', $laevatain->getName());
        $this->assertSame(6, $laevatain->getRarity());
        $this->assertTrue($laevatain->isFree());
        $this->assertSame(2, $laevatain->getPoolVersion());

        $dossierPull = $pulls['1047'];
        $this->assertSame('gift_intel_book', $dossierPull->getKind());
        $this->assertFalse($dossierPull->isDraw());
        $this->assertSame('Headhunting Dossier', $dossierPull->getName());
        $this->assertNull($dossierPull->getRarity());
        $this->assertNull($dossierPull->getItemId());
        $this->assertFalse($dossierPull->isFree());
        $this->assertNull($dossierPull->getPoolVersion());
        $this->assertSame(self::CHARTERED, $dossierPull->getPoolType());
        $this->assertSame('char', $dossierPull->getPoolCategory());
        $this->assertSame($dossier, $dossierPull->getRawPayload());

        $tokenPull = $pulls['1046'];
        $this->assertSame('gift_operator_token', $tokenPull->getKind());
        $this->assertSame('Token Tribute', $tokenPull->getName());
        $this->assertNull($tokenPull->getRarity());
        // poolVersion 0 is "no rerun number"
        $this->assertNull($tokenPull->getPoolVersion());

        $this->assertSame([], $this->warnings());
    }

    public function testKnownKindsAreStoredWithoutWarningAndUnknownKindsWithOne(): void
    {
        $rows = [
            self::operatorDraw(['seqId' => '2000']),
            self::bonusEntry(['seqId' => '1999']),
            self::bonusEntry(['kind' => 'gift_operator_token', 'nameText' => 'Token Tribute', 'seqId' => '1998']),
            self::bonusEntry(['kind' => 'gift_weapon_box', 'nameText' => 'Arms Offering', 'seqId' => '1997']),
            self::bonusEntry(['kind' => 'gift_weapon', 'nameText' => 'Tribute', 'seqId' => '1996']),
            self::bonusEntry(['kind' => 'gift', 'nameText' => 'Tribute', 'seqId' => '1995']),
            self::bonusEntry(['kind' => 'gift_named_thing', 'nameText' => 'Named Thing', 'seqId' => '1994']),
        ];

        $service = $this->createService([self::page($rows, false)]);

        $nextSeqId = null;
        $pulls = self::bySeqId($service->fetchPullsPage($this->createProfile(), self::CHARTERED, self::TOKEN, null, $nextSeqId));

        $this->assertNull($nextSeqId);
        $this->assertCount(7, $pulls);
        $kinds = array_map(static fn (GryphlinePull $pull): string => $pull->getKind(), $pulls);
        $this->assertSame(
            [2000 => 'draw', 1999 => 'gift_intel_book', 1998 => 'gift_operator_token', 1997 => 'gift_weapon_box', 1996 => 'gift_weapon', 1995 => 'gift', 1994 => 'gift_named_thing'],
            $kinds,
        );
        foreach ($pulls as $seqId => $pull) {
            $this->assertSame((int) $seqId === 2000, $pull->isDraw(), (string) $seqId);
        }

        // An unknown kind is stored verbatim, with its own name, and reported once
        $this->assertSame('Named Thing', $pulls['1994']->getName());
        $this->assertNull($pulls['1994']->getRarity());
        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('"gift_named_thing"', $warnings[0]);
        $this->assertStringContainsString('1994', $warnings[0]);
        $this->assertStringContainsString('Endministrator', $warnings[0]);
    }

    public function testBonusEntryWithoutANameGetsTheLabelOfItsKind(): void
    {
        $rows = [];
        $seqId = 1990;
        foreach (['gift_intel_book', 'gift_operator_token', 'gift_weapon_box', 'gift_weapon', 'gift', 'gift_mystery_box'] as $kind) {
            $rows[] = array_diff_key(self::bonusEntry(['kind' => $kind, 'seqId' => (string) $seqId--]), ['nameText' => true]);
        }

        $service = $this->createService([self::page($rows, false)]);

        $nextSeqId = null;
        $pulls = self::bySeqId($service->fetchPullsPage($this->createProfile(), self::CHARTERED, self::TOKEN, null, $nextSeqId));

        $this->assertCount(6, $pulls);
        $this->assertSame('Headhunting Dossier', $pulls['1990']->getName());
        $this->assertSame('Token Tribute', $pulls['1989']->getName());
        $this->assertSame('Arms Offering', $pulls['1988']->getName());
        $this->assertSame('Tribute', $pulls['1987']->getName());
        $this->assertSame('Tribute', $pulls['1986']->getName());
        $this->assertSame('gift_mystery_box', $pulls['1985']->getKind());
        $this->assertSame(GryphlinePull::getBonusLabel('gift_mystery_box'), $pulls['1985']->getName());
        $this->assertSame('Gift mystery box', $pulls['1985']->getName());
        foreach ($pulls as $pull) {
            $this->assertNull($pull->getRarity());
            $this->assertNull($pull->getItemId());
        }

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('"gift_mystery_box"', $warnings[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operatorStreamProvider(): iterable
    {
        yield 'Chartered' => [self::CHARTERED];
        yield 'RE-Factor' => [self::RERUN];
        yield 'Special' => [self::JOINT];
        yield 'Basic' => [self::STANDARD];
        yield 'Beginner' => [self::BEGINNER];
    }

    public function testRecordWithoutGachaTsIsLeftOut(): void
    {
        $dated = self::bonusEntry(['seqId' => '1980']);
        $undated = array_diff_key(self::bonusEntry(['seqId' => '1979']), ['gachaTs' => true]);
        $service = $this->createService([self::page([$dated, $undated], false)]);

        $nextSeqId = null;
        $pulls = self::bySeqId($service->fetchPullsPage($this->createProfile(), self::CHARTERED, self::TOKEN, null, $nextSeqId));

        // A record without a date cannot be stored: it is skipped instead of failing the whole page
        $this->assertSame(['1980'], array_map('strval', array_keys($pulls)));
    }

    #[DataProvider('operatorStreamProvider')]
    public function testOperatorStreamsQueryTheirPoolTypeOnTheCharEndpoint(string $stream): void
    {
        $service = $this->createService([self::page([self::operatorDraw()], false)]);

        $nextSeqId = 'stale';
        $pulls = $service->fetchPullsPage($this->createProfile(), $stream, self::TOKEN, null, $nextSeqId);

        $request = $this->request(0);
        $this->assertSame(self::CHAR_PATH, $request['path']);
        self::assertQuery([
            'server_id' => '2',
            'lang' => 'en-us',
            'token' => self::TOKEN,
            'pool_type' => $stream,
        ], $request['query']);

        // Last page of the stream: no next seq id
        $this->assertNull($nextSeqId);
        $this->assertCount(1, $pulls);
        $this->assertSame($stream, $pulls[0]->getPoolType());
        $this->assertSame('char', $pulls[0]->getPoolCategory());
    }

    public function testJointPageSendsItsPoolId(): void
    {
        $service = $this->createService([
            self::page([self::operatorDraw(['poolId' => 'joint_1_2_2', 'poolName' => 'Joint Ops', 'seqId' => '320'])], true),
        ]);

        $nextSeqId = null;
        $pulls = $service->fetchPullsPage($this->createProfile(), self::JOINT, self::TOKEN, '321', $nextSeqId, 'joint_1_2_2');

        $request = $this->request(0);
        $this->assertSame(self::CHAR_PATH, $request['path']);
        self::assertQuery([
            'server_id' => '2',
            'lang' => 'en-us',
            'token' => self::TOKEN,
            'pool_type' => self::JOINT,
            'pool_id' => 'joint_1_2_2',
            'seq_id' => '321',
        ], $request['query']);

        $this->assertSame('320', $nextSeqId);
        $this->assertCount(1, $pulls);
        $this->assertSame(self::JOINT, $pulls[0]->getPoolType());
        $this->assertSame('joint_1_2_2', $pulls[0]->getPoolId());
    }

    public function testEmptyPoolCodeReadsAsAnEmptyLastPage(): void
    {
        $service = $this->createService([self::answer(['code' => 50000, 'msg' => 'save seq id unavailable', 'data' => null])]);

        $nextSeqId = 'stale';
        $pulls = $service->fetchPullsPage($this->createProfile(), self::JOINT, self::TOKEN, null, $nextSeqId, 'joint_1_2_2');

        $this->assertSame([], $pulls);
        $this->assertNull($nextSeqId);
        $this->assertCount(1, $this->requests);
        $this->assertSame([], $this->warnings());
    }

    public function testRecordApiErrorCodeThrows(): void
    {
        $service = $this->createService([self::answer(['code' => 42900, 'msg' => 'too many requests'])]);

        $this->expectException(SkportApiException::class);
        $this->expectExceptionMessage('42900');

        $nextSeqId = null;
        $service->fetchPullsPage($this->createProfile(), self::CHARTERED, self::TOKEN, null, $nextSeqId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function weaponStreamProvider(): iterable
    {
        yield 'weapon stream' => [self::WEAPON];
        yield 'legacy weapon alias' => ['weapon'];
    }

    /**
     * Weapon rows: the record poolType, the catalog pool type, and the weapon group each one must go to.
     *
     * @return list<array{0: array<string, mixed>, 1: string, 2: string}> [row, group with catalog, group without catalog]
     */
    private static function weaponRows(): array
    {
        return [
            // Normal banner, catalog agrees
            [self::weaponDraw(['seqId' => '549', 'poolId' => 'weaponbox_1_6_1', 'poolType' => 'normal']), self::WEAPON, self::WEAPON],
            // Rerun banner, catalog agrees
            [self::weaponDraw(['seqId' => '548', 'poolId' => 'weaponbox_rerun_1_6_1', 'poolType' => 'rerun']), self::WEAPON_RERUN, self::WEAPON_RERUN],
            // The record says normal, the catalog says rerun (warning)
            [self::weaponDraw(['seqId' => '547', 'poolId' => 'weaponbox_1_4_1', 'poolType' => 'normal']), self::WEAPON_RERUN, self::WEAPON],
            // The record says rerun, the catalog says normal (warning)
            [self::weaponDraw(['seqId' => '546', 'poolId' => 'weaponbox_1_5_1', 'poolType' => 'rerun']), self::WEAPON_RERUN, self::WEAPON_RERUN],
            // Permanent weapon banner: Arsenal Issue
            [self::weaponDraw(['seqId' => '545', 'poolId' => 'weaponbox_constant_1', 'poolType' => 'normal', 'poolName' => 'Arsenal Issue']), self::WEAPON, self::WEAPON],
            // Banners unknown to the catalog: the record decides
            [self::weaponDraw(['seqId' => '544', 'poolId' => 'weaponbox_9_9_1', 'poolType' => 'rerun']), self::WEAPON_RERUN, self::WEAPON_RERUN],
            [self::weaponDraw(['seqId' => '543', 'poolId' => 'weaponbox_9_9_2', 'poolType' => 'normal']), self::WEAPON, self::WEAPON],
            // A record without poolType: the catalog decides (no disagreement to report)
            [array_diff_key(self::weaponDraw(['seqId' => '542', 'poolId' => 'weaponbox_1_4_1']), ['poolType' => true]), self::WEAPON_RERUN, self::WEAPON],
            // A bonus entry of a rerun banner follows its banner
            [['kind' => 'gift_weapon', 'seqId' => '541', 'poolId' => 'weaponbox_rerun_1_6_1', 'poolName' => 'Rerun Issue', 'poolType' => 'rerun', 'nameText' => 'Tribute', 'gachaTs' => '1792073868000', 'poolVersion' => 3], self::WEAPON_RERUN, self::WEAPON_RERUN],
        ];
    }

    #[DataProvider('weaponStreamProvider')]
    public function testWeaponPageSplitsRowsBetweenArsenalAndReFactorIssueWithTheCatalog(string $stream): void
    {
        $rows = self::weaponRows();
        $service = $this->createService([self::page(array_column($rows, 0), true)], self::createCatalog());

        $nextSeqId = null;
        // A pool id passed for the weapon stream is never sent: the weapon endpoint returns every weapon banner
        $pulls = $service->fetchPullsPage($this->createProfile(), $stream, self::TOKEN, '550', $nextSeqId, 'weaponbox_1_6_1');

        $this->assertCount(1, $this->requests);
        $request = $this->request(0);
        $this->assertSame(self::WEAPON_PATH, $request['path']);
        $this->assertArrayNotHasKey('pool_type', $request['query']);
        $this->assertArrayNotHasKey('pool_id', $request['query']);
        self::assertQuery([
            'server_id' => '2',
            'lang' => 'en-us',
            'token' => self::TOKEN,
            'seq_id' => '550',
        ], $request['query']);

        $this->assertSame('541', $nextSeqId);
        $pulls = self::bySeqId($pulls);
        $this->assertCount(count($rows), $pulls);
        foreach ($rows as [$row, $expectedGroup]) {
            $pull = $pulls[$row['seqId']];
            $this->assertSame($expectedGroup, $pull->getPoolType(), sprintf('Weapon group of record %s (%s)', $row['seqId'], $row['poolId']));
            $this->assertSame('weapon', $pull->getPoolCategory());
            $this->assertSame($row['poolId'], $pull->getPoolId());
        }

        $hypernova = $pulls['549'];
        $this->assertSame('Hypernova Auto', $hypernova->getName());
        $this->assertSame('wpn_funnel_0001', $hypernova->getItemId());
        $this->assertSame(4, $hypernova->getRarity());
        $this->assertSame('Seal of Jade Issue', $hypernova->getPoolName());
        $this->assertSame(GryphlinePull::KIND_DRAW, $hypernova->getKind());

        $tribute = $pulls['541'];
        $this->assertSame('gift_weapon', $tribute->getKind());
        $this->assertSame('Tribute', $tribute->getName());
        $this->assertNull($tribute->getRarity());
        $this->assertSame(3, $tribute->getPoolVersion());

        // Only the two records whose poolType disagrees with the catalog are reported
        $warnings = $this->warnings();
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('weaponbox_1_4_1', $warnings[0]);
        $this->assertStringContainsString('"normal"', $warnings[0]);
        $this->assertStringContainsString('weaponbox_1_5_1', $warnings[1]);
        $this->assertStringContainsString('"rerun"', $warnings[1]);
    }

    public function testWeaponPageWithoutCatalogTrustsTheRecordOnly(): void
    {
        $rows = self::weaponRows();
        $service = $this->createService([self::page(array_column($rows, 0), false)]);
        $this->assertNull($service->getCatalog());

        $nextSeqId = 'stale';
        $pulls = self::bySeqId($service->fetchPullsPage($this->createProfile(), self::WEAPON, self::TOKEN, null, $nextSeqId));

        $request = $this->request(0);
        $this->assertSame(self::WEAPON_PATH, $request['path']);
        self::assertQuery([
            'server_id' => '2',
            'lang' => 'en-us',
            'token' => self::TOKEN,
        ], $request['query']);

        $this->assertNull($nextSeqId);
        $this->assertCount(count($rows), $pulls);
        foreach ($rows as [$row, , $expectedGroup]) {
            $this->assertSame($expectedGroup, $pulls[$row['seqId']]->getPoolType(), sprintf('Weapon group of record %s (%s)', $row['seqId'], $row['poolId']));
        }
        $this->assertSame([], $this->warnings());
    }

    public function testPoolVersionIsKeptOnlyWhenAPositiveInteger(): void
    {
        $item = new GryphlinePullItem();

        $this->assertSame(2, $item->setPoolVersion(2)->getPoolVersion());
        $this->assertSame(3, $item->setPoolVersion('3')->getPoolVersion());
        $this->assertNull($item->setPoolVersion(0)->getPoolVersion());
        $this->assertNull($item->setPoolVersion('0')->getPoolVersion());
        $this->assertNull($item->setPoolVersion(-1)->getPoolVersion());
        $this->assertNull($item->setPoolVersion('abc')->getPoolVersion());
        $this->assertNull($item->setPoolVersion('')->getPoolVersion());
        $this->assertNull($item->setPoolVersion(null)->getPoolVersion());
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, list<string>}>
     */
    public static function metaTabsProvider(): iterable
    {
        $special = ['key' => 'special', 'label' => '', 'poolType' => self::CHARTERED];
        $joint = static fn (string $poolId): array => ['key' => 'joint:' . $poolId, 'label' => '', 'poolType' => self::JOINT, 'poolId' => $poolId];

        yield 'no Special Headhunting banner' => [[$special], []];
        yield 'one banner' => [[$special, $joint('joint_1_2_2')], ['joint_1_2_2']];
        yield 'two banners' => [[$special, $joint('joint_1_2_2'), $joint('joint_1_3_1')], ['joint_1_2_2', 'joint_1_3_1']];
        // A pool id on a tab of another pool type is not a Special Headhunting banner
        yield 'pool id of another pool type' => [[$special + ['poolId' => 'special_1_5_1'], $joint('joint_1_3_1')], ['joint_1_3_1']];
    }

    /**
     * @param list<array<string, mixed>> $tabs
     * @param list<string> $expectedPoolIds
     */
    #[DataProvider('metaTabsProvider')]
    public function testFetchStreamPoolIdsListsTheJointTabsOfTheMeta(array $tabs, array $expectedPoolIds): void
    {
        $service = $this->createService([
            self::answer(['code' => 0, 'data' => ['tabs' => $tabs, 'beginnerPullCount' => 40]]),
        ], realWiring: false);

        $this->assertSame($expectedPoolIds, $service->fetchStreamPoolIds($this->createProfile(), self::JOINT, self::TOKEN));

        $this->assertCount(1, $this->requests);
        $request = $this->request(0);
        $this->assertSame(self::META_PATH, $request['path']);
        self::assertQuery([
            'lang' => 'en-us',
            'token' => self::TOKEN,
            'server_id' => '2',
        ], $request['query']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function streamWithoutPoolIdProvider(): iterable
    {
        yield 'Chartered' => [self::CHARTERED];
        yield 'RE-Factor' => [self::RERUN];
        yield 'Basic' => [self::STANDARD];
        yield 'weapons' => [self::WEAPON];
        yield 'weapon rerun' => [self::WEAPON_RERUN];
        yield 'Beginner' => [self::BEGINNER];
        yield 'legacy char alias' => ['char'];
        yield 'legacy weapon alias' => ['weapon'];
    }

    #[DataProvider('streamWithoutPoolIdProvider')]
    public function testStreamsWithoutPoolIdNeverCallTheMeta(string $stream): void
    {
        $service = $this->createService(realWiring: false);

        $this->assertFalse($service->requiresPoolId($stream));
        $this->assertSame([], $service->fetchStreamPoolIds($this->createProfile(), $stream, self::TOKEN));
        $this->assertSame([], $this->requests);
    }

    public function testFetchStreamPoolIdsThrowsOnAnApiError(): void
    {
        $service = $this->createService([self::answer(['code' => 42900, 'msg' => 'too many requests'])], realWiring: false);

        $this->expectException(SkportApiException::class);
        $this->expectExceptionMessage('42900');

        $service->fetchStreamPoolIds($this->createProfile(), self::JOINT, self::TOKEN);
    }

    public function testOnlyTheJointStreamRequiresAPoolId(): void
    {
        $service = $this->createService(realWiring: false);

        $this->assertTrue($service->requiresPoolId(self::JOINT));
        foreach ([self::CHARTERED, self::RERUN, self::STANDARD, self::WEAPON, self::WEAPON_RERUN, self::BEGINNER, 'char', 'weapon'] as $stream) {
            $this->assertFalse($service->requiresPoolId($stream), $stream);
        }
    }

    public function testPoolPityBanners(): void
    {
        $service = $this->createService(realWiring: false);

        foreach ([self::JOINT, self::WEAPON, self::WEAPON_RERUN] as $bannerKey) {
            $this->assertTrue($service->isPoolPityBanner($bannerKey), $bannerKey);
        }
        foreach ([self::CHARTERED, self::RERUN, self::STANDARD, self::BEGINNER] as $bannerKey) {
            $this->assertFalse($service->isPoolPityBanner($bannerKey), $bannerKey);
        }
    }

    public function testSyncStreamsAndTheirBanners(): void
    {
        $service = $this->createService(realWiring: false);

        $streams = $service->getSyncStreams();
        $this->assertSame([self::CHARTERED, self::RERUN, self::JOINT, self::STANDARD, self::WEAPON, self::BEGINNER], array_keys($streams));
        $this->assertSame('Chartered Headhunting', $streams[self::CHARTERED]);
        $this->assertSame('Special Headhunting', $streams[self::JOINT]);
        foreach ($streams as $stream => $name) {
            $this->assertNotSame('', $name, $stream);
        }

        // One weapon stream feeds both weapon groups; the legacy alias resolves the same
        $this->assertSame([self::WEAPON, self::WEAPON_RERUN], $service->getSyncStreamBanners(self::WEAPON));
        $this->assertSame([self::WEAPON, self::WEAPON_RERUN], $service->getSyncStreamBanners('weapon'));

        foreach ([self::CHARTERED, self::RERUN, self::JOINT, self::STANDARD, self::BEGINNER] as $stream) {
            $this->assertSame([$stream], $service->getSyncStreamBanners($stream), $stream);
        }
        $this->assertSame([self::CHARTERED], $service->getSyncStreamBanners('char'));

        // Every banner a stream feeds is a banner group of the gacha config
        $bannerGroups = $service->getGachaConfig()->getBannerGroups('categories');
        foreach (array_keys($streams) as $stream) {
            foreach ($service->getSyncStreamBanners($stream) as $bannerKey) {
                $this->assertArrayHasKey($bannerKey, $bannerGroups, $bannerKey);
            }
        }
    }
}

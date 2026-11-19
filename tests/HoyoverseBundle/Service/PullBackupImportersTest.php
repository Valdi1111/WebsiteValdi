<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\Pull\Importer\PaimonMoePullImporter;
use App\HoyoverseBundle\Service\Pull\Importer\StarRailStationCsvPullImporter;
use App\HoyoverseBundle\Service\Pull\Importer\ZzzRngMoePullImporter;
use App\HoyoverseBundle\Service\Pull\NanokaCatalogService;
use App\HoyoverseBundle\Service\Pull\PullBackupImporterManager;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PullBackupImportersTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    private function writeTempFile(string $content, string $ext): string
    {
        $path = sys_get_temp_dir() . '/pull_test_' . bin2hex(random_bytes(6)) . $ext;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function createCatalogService(): NanokaCatalogService
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            return match ($url) {
                'https://static.nanoka.cc/manifest.json' => new MockResponse(json_encode([
                    'gi' => ['live' => '7.2'],
                    'hsr' => ['live' => '4.6'],
                    'zzz' => ['live' => '3.3'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/gi/7.2/character.json' => new MockResponse(json_encode([
                    '10000002' => ['en' => 'Kamisato Ayaka', 'rank' => 'QUALITY_ORANGE', 'icon' => 'UI_AvatarIcon_Ayaka'],
                    '10000034' => ['en' => 'Noelle', 'rank' => 'QUALITY_PURPLE', 'icon' => 'UI_AvatarIcon_Noel'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/gi/7.2/weapon.json' => new MockResponse(json_encode([
                    '11302' => ['en' => 'Harbinger of Dawn', 'rank' => 3, 'icon' => 'UI_EquipIcon_Sword_Dawn'],
                    '13430' => ['en' => 'Mountain-Bracing Bolt', 'rank' => 4, 'icon' => 'UI_EquipIcon_Pole_Bolt'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/hsr/4.6/character.json' => new MockResponse(json_encode([
                    '1004' => ['en' => 'Welt', 'rank' => 'CombatPowerAvatarRarityType5'],
                    '1506' => ['en' => 'Silver Wolf LV.<unbreak>999</unbreak>', 'rank' => 'CombatPowerAvatarRarityType5'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/hsr/4.6/lightcone.json' => new MockResponse(json_encode([
                    '20019' => ['en' => 'Mediation', 'rank' => 'CombatPowerLightconeRarity3'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/zzz/3.3/character.json' => new MockResponse(json_encode([
                    '1061' => ['en' => 'Corin', 'rank' => 3, 'icon' => 'IconRole06'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/zzz/3.3/weapon.json' => new MockResponse(json_encode([
                    '12005' => ['en' => '[Reverb] Mark II', 'rank' => 2, 'icon' => 'Weapon_B_05'],
                ], JSON_THROW_ON_ERROR)),
                'https://static.nanoka.cc/zzz/3.3/bangboo.json' => new MockResponse(json_encode([
                    '53010' => ['en' => 'Electroboo', 'rank' => 3, 'icon' => 'UI/Sprite/Bangboo10.png'],
                ], JSON_THROW_ON_ERROR)),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        }, 'https://static.nanoka.cc');

        return new NanokaCatalogService($client, new ArrayAdapter());
    }

    public function testPaimonMoeValidateParseAndReconcile(): void
    {
        $catalog = $this->createCatalogService();
        $tz = new HoyoverseServerTimezoneResolver();
        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $importer = new PaimonMoePullImporter($catalog, $tz, $pullRepo);

        // Invalid JSON
        $invalidPath = $this->writeTempFile('{broken', '.json');
        $this->assertFalse($importer->validate($invalidPath)->valid);

        // Unrelated JSON
        $wrongPath = $this->writeTempFile('{"foo":"bar"}', '.json');
        $this->assertFalse($importer->validate($wrongPath)->valid);

        // Valid backup with same-second multi-pull + missing code fallback + unresolved slug
        $payload = [
            'wish-counter-character-event' => [
                'pulls' => [
                    ['type' => 'weapon', 'id' => 'harbinger_of_dawn', 'time' => '2026-01-14 08:02:12', 'pity' => 1],
                    ['type' => 'weapon', 'id' => 'harbinger_of_dawn', 'time' => '2026-01-14 08:02:12', 'pity' => 1],
                    ['type' => 'weapon', 'code' => '400', 'id' => 'mountain-bracing_bolt', 'time' => '2026-01-14 08:02:12', 'pity' => 3],
                    ['type' => 'character', 'code' => '301', 'id' => 'kamisato_ayaka', 'time' => '2026-01-14 08:02:12', 'pity' => 75],
                    ['type' => 'weapon', 'code' => '301', 'id' => 'brand_new_blade', 'time' => '2026-01-14 08:05:00', 'pity' => 1],
                ],
            ],
        ];
        $path = $this->writeTempFile(json_encode($payload, JSON_THROW_ON_ERROR), '.json');

        $validation = $importer->validate($path);
        $this->assertTrue($validation->valid);
        $this->assertSame(5, $validation->estimatedCount);

        $profile = (new HoyoverseGameProfile())->setRegion('os_euro');
        $records = $importer->parse($profile, $path);
        $this->assertCount(5, $records);

        // Same-second pulls get monotonically increasing synthetic IDs
        $this->assertSame('paimon_301_20260114080212_000', $records[0]->pullId);
        $this->assertSame('paimon_301_20260114080212_001', $records[1]->pullId);
        $this->assertSame('Harbinger of Dawn', $records[0]->name);
        $this->assertSame(3, $records[0]->rarity);
        $this->assertSame('301', $records[0]->gachaType);
        $this->assertTrue($records[0]->isSyntheticId);

        // Code 400 + hyphenated slug
        $this->assertSame('400', $records[2]->gachaType);
        $this->assertSame('Mountain-Bracing Bolt', $records[2]->name);
        $this->assertSame(4, $records[2]->rarity);

        // 5-star character
        $this->assertSame('Kamisato Ayaka', $records[3]->name);
        $this->assertSame(5, $records[3]->rarity);
        $this->assertSame('Character', $records[3]->itemType);

        // Unresolved slug falls back cleanly with warning
        $this->assertSame('Brand New Blade', $records[4]->name);
        $this->assertNotNull($records[4]->warning);

        // Test live-sync reconciliation promotes newest-first without double-matching the same entity
        $ts = new \DateTimeImmutable('2026-01-14 08:02:12', new \DateTimeZone('UTC'));
        $synth1 = (new HoyoversePull())
            ->setPullId('paimon_301_20260114080212_001')
            ->setGachaType('301')
            ->setName('Harbinger of Dawn')
            ->setPulledAt($ts);
        $synth0 = (new HoyoversePull())
            ->setPullId('paimon_301_20260114080212_000')
            ->setGachaType('301')
            ->setName('Harbinger of Dawn')
            ->setPulledAt($ts);

        $pullRepo->method('findSyntheticCandidatesForReconciliation')
            ->willReturn([$synth1, $synth0]);

        $giService = new GenshinImpactService(new NullLogger());

        $realTop = (new HoyoversePull())
            ->setPullId('1790000000000000002')
            ->setGachaType('400')
            ->setGachaName('Character Event Wish')
            ->setItemType('Weapon')
            ->setItemId('')
            ->setName('Harbinger of Dawn')
            ->setRarity(3)
            ->setPulledAt($ts)
            ->setRawPayload(['id' => '1790000000000000002']);

        $realSecond = (new HoyoversePull())
            ->setPullId('1790000000000000001')
            ->setGachaType('400')
            ->setGachaName('Character Event Wish')
            ->setItemType('Weapon')
            ->setItemId('')
            ->setName('Harbinger of Dawn')
            ->setRarity(3)
            ->setPulledAt($ts)
            ->setRawPayload(['id' => '1790000000000000001']);

        $promotedFirst = $importer->reconcile($profile, $realTop, $giService);
        $this->assertSame($synth1, $promotedFirst);
        $this->assertSame('1790000000000000002', $synth1->getPullId());
        $this->assertSame('400', $synth1->getGachaType());

        // Second call within same unflushed UoW skips already-promoted $synth1 and promotes $synth0
        $promotedSecond = $importer->reconcile($profile, $realSecond, $giService);
        $this->assertSame($synth0, $promotedSecond);
        $this->assertSame('1790000000000000001', $synth0->getPullId());
    }

    public function testStarRailStationCsvValidateAndParseWithTimezoneAndRichTextStrip(): void
    {
        $catalog = $this->createCatalogService();
        $tz = new HoyoverseServerTimezoneResolver();
        $importer = new StarRailStationCsvPullImporter($catalog, $tz);

        $badCsv = $this->writeTempFile("foo,bar\n1,2\n", '.csv');
        $this->assertFalse($importer->validate($badCsv)->valid);

        // Include UTF-8 BOM and summer UTC timestamp (verify EU stays fixed +01:00 without DST)
        $csv = "\xEF\xBB\xBFuid,id,rarity,time,banner,type,manual\n"
            . "1763622600000042581,1004,5,2025-11-20T07:29:35.000Z,2092,11,false\n"
            . "1763622600000042681,20019,3,2026-07-15T10:00:00.000Z,2092,11,false\n"
            . "1763622600000042781,1506,5,2026-07-15T10:00:05.000Z,2092,11,false\n";
        $path = $this->writeTempFile($csv, '.csv');

        $validation = $importer->validate($path);
        $this->assertTrue($validation->valid);
        $this->assertSame(3, $validation->estimatedCount);

        $euProfile = (new HoyoverseGameProfile())->setRegion('prod_official_eur');
        $records = $importer->parse($euProfile, $path);
        $this->assertCount(3, $records);

        $this->assertSame('1763622600000042581', $records[0]->pullId);
        $this->assertSame('Welt', $records[0]->name);
        $this->assertSame('Character', $records[0]->itemType);
        $this->assertSame('2025-11-20 08:29:35', $records[0]->pulledAt->format('Y-m-d H:i:s'));

        // Summer date in EU is still fixed UTC+1 (11:00:00, NOT 12:00:00)
        $this->assertSame('Mediation', $records[1]->name);
        $this->assertSame('Light Cone', $records[1]->itemType);
        $this->assertSame('2026-07-15 11:00:00', $records[1]->pulledAt->format('Y-m-d H:i:s'));

        // Rich-text <unbreak> stripped
        $this->assertSame('Silver Wolf LV.999', $records[2]->name);
    }

    public function testZzzRngMoeValidateAndParseChannelsAndTimezones(): void
    {
        $catalog = $this->createCatalogService();
        $tz = new HoyoverseServerTimezoneResolver();
        $importer = new ZzzRngMoePullImporter($catalog, $tz);

        $wrongGame = $this->writeTempFile(json_encode(['game' => 'hsr', 'data' => []], JSON_THROW_ON_ERROR), '.json');
        $this->assertFalse($importer->validate($wrongGame)->valid);

        // 2026-05-18 14:17:09 UTC = 1779113829000 ms -> EU server time 2026-05-18 15:17:09
        $tsMs = (new \DateTimeImmutable('2026-05-18 14:17:09', new \DateTimeZone('UTC')))->getTimestamp() * 1000;

        $backup = [
            'version' => 1,
            'game' => 'zzz',
            'data' => [
                'curProfileId' => 1,
                'profiles' => [
                    '1' => [
                        'stores' => [
                            '0' => [
                                'items' => [
                                    '1001' => [
                                        ['uid' => '1779112800000013748', 'id' => 12005, 'timestamp' => $tsMs, 'rarity' => 2, 'gacha' => 1001001, 'gachaType' => 1001],
                                        ['uid' => '1779112800000013848', 'id' => 1061, 'timestamp' => $tsMs + 5000, 'rarity' => 3, 'gacha' => 1001001, 'gachaType' => 1001],
                                    ],
                                    '5001' => [
                                        ['uid' => '1786737600000020648', 'id' => 53010, 'timestamp' => $tsMs + 10000, 'rarity' => 3, 'gacha' => 5001001, 'gachaType' => 5001],
                                    ],
                                    '12001' => [
                                        ['uid' => '1769612400000006648', 'id' => 12005, 'timestamp' => $tsMs + 20000, 'rarity' => 2, 'gacha' => 2002001, 'gachaType' => 12001],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $path = $this->writeTempFile(json_encode($backup, JSON_THROW_ON_ERROR), '.json');
        $validation = $importer->validate($path);
        $this->assertTrue($validation->valid);
        $this->assertSame(4, $validation->estimatedCount);

        $euProfile = (new HoyoverseGameProfile())->setRegion('prod_gf_eu');
        $records = $importer->parse($euProfile, $path);
        $this->assertCount(4, $records);

        $this->assertSame('1', $records[0]->gachaType);
        $this->assertSame('W-Engines', $records[0]->itemType);
        $this->assertSame('[Reverb] Mark II', $records[0]->name);
        $this->assertSame('2026-05-18 15:17:09', $records[0]->pulledAt->format('Y-m-d H:i:s'));

        $this->assertSame('Agents', $records[1]->itemType);
        $this->assertSame('Corin', $records[1]->name);

        $this->assertSame('5', $records[2]->gachaType);
        $this->assertSame('Bangboo', $records[2]->itemType);
        $this->assertSame('Electroboo', $records[2]->name);

        $this->assertSame('12', $records[3]->gachaType);
    }

    public function testImporterManagerFiltersByGameService(): void
    {
        $catalog = $this->createCatalogService();
        $tz = new HoyoverseServerTimezoneResolver();
        $pullRepo = $this->createStub(HoyoversePullRepository::class);

        $manager = new PullBackupImporterManager([
            new PaimonMoePullImporter($catalog, $tz, $pullRepo),
            new StarRailStationCsvPullImporter($catalog, $tz),
            new ZzzRngMoePullImporter($catalog, $tz),
        ]);

        $giMeta = $manager->getMetadataForGameService(GenshinImpactService::class);
        $this->assertCount(1, $giMeta);
        $this->assertSame('paimon_moe', $giMeta[0]['key']);
        $this->assertSame('.json', $giMeta[0]['accept']);

        $hsrMeta = $manager->getMetadataForGameService(HonkaiStarRailService::class);
        $this->assertCount(1, $hsrMeta);
        $this->assertSame('star_rail_station_csv', $hsrMeta[0]['key']);
        $this->assertSame('.csv', $hsrMeta[0]['accept']);

        $zzzMeta = $manager->getMetadataForGameService(ZenlessZoneZeroService::class);
        $this->assertCount(1, $zzzMeta);
        $this->assertSame('zzz_rng_moe', $zzzMeta[0]['key']);
    }
}

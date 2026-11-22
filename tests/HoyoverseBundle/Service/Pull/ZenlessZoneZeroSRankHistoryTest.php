<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\GamesBundle\Model\Pull\SRankHistory\SRankHistoryRecord;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveSRankHistoryException;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * ZenlessZoneZeroSRankHistoryTrait against responses shaped like the HoYoLAB "Signal Search" statistics (made-up
 * values): which requests are sent, and how the records become SRankHistoryRecord.
 */
class ZenlessZoneZeroSRankHistoryTest extends TestCase
{
    /** @var list<array{endpoint: string, query: array<string, string>, headers: array<string, list<string>>}> */
    private array $requests = [];

    /**
     * @param callable(string, array<string, string>): array<string, mixed> $respond Body of a request (endpoint, query)
     */
    private function createService(callable $respond, ?MockClock $clock = null): ZenlessZoneZeroService
    {
        $service = new ZenlessZoneZeroService(new NullLogger());
        $service->setServerTimezoneResolver(new HoyoverseServerTimezoneResolver());
        if ($clock !== null) {
            $service->setSRankHistoryClock($clock);
        }
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $endpoint = basename((string) parse_url($url, PHP_URL_PATH));
            $headers = [];
            foreach ($options['normalized_headers'] ?? [] as $name => $values) {
                $headers[$name] = $values;
            }
            $this->requests[] = ['endpoint' => $endpoint, 'query' => $query, 'headers' => $headers];
            $this->assertSame('GET', $method);
            $this->assertStringStartsWith(ZenlessZoneZeroService::S_RANK_HISTORY_URL, $url);

            return new MockResponse(json_encode($respond($endpoint, $query), JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        }));

        return $service;
    }

    private function createAccount(): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())
            ->setGameId(ZenlessZoneZeroService::GAME_ID)
            ->setGameUid('1300000001')
            ->setRegion('prod_gf_eu')
            ->setNickname('Proxy');

        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test')->setStokenV2('v2_stoken_test'))
            ->setGameProfile($profile);
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    private static function phase(string $version, string $state, array $records): array
    {
        return [
            'gacha_type' => 'GACHA_TYPE_CHARACTER_UP',
            'gacha_state' => $state,
            'start_ts' => 0,
            'end_ts' => 0,
            'version' => $version,
            'cur_item_list' => [],
            'cur_records' => $records,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(string $name, string $itemType, int $sort, int $count, int $epoch, bool $isUp, string $itemId = '1001'): array
    {
        return [
            'item_id' => $itemId,
            'name' => $name,
            'icon' => 'https://example.test/icon.png',
            'item_type' => $itemType,
            'rarity' => 'S',
            'is_up' => $isUp,
            'up_tag' => 'UP_TAG_NONE',
            'count' => (string) $count,
            'record_time' => (string) $epoch,
            'sort' => (string) $sort,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function ok(array $data): array
    {
        return ['retcode' => 0, 'message' => 'OK', 'data' => $data];
    }

    /**
     * Thirteen versions with data on the Exclusive Channel (the current one included), nothing on the rerun banner, the
     * current version only on the Bangboo Channel, and a banner type this code does not know.
     *
     * @return array<string, mixed>
     */
    private static function respond(string $endpoint, array $query): array
    {
        $exclusiveVersions = ['3.0', '2.7', '2.6', '2.5', '2.4', '2.3', '2.2', '2.1', '2.0', '1.7', '1.6', '1.5', '1.4'];

        return match ([$endpoint, $query['gacha_type'] ?? null]) {
            ['index', null] => self::ok([
                'cur_ver' => '3.0',
                'type_stats' => [
                    ['gacha_type' => 'GACHA_TYPE_CHARACTER_UP', 'total_count' => '181', 's_count' => '3', 'up_count' => '2', 'has_data_vers' => $exclusiveVersions, 'new_s_max_times' => 70, 'next_s_cur_times' => 11],
                    ['gacha_type' => 'GACHA_TYPE_CHARACTER_RETURN', 'total_count' => '0', 's_count' => '0', 'has_data_vers' => [], 'next_s_cur_times' => 0],
                    ['gacha_type' => 'GACHA_TYPE_BANGBOO', 'total_count' => 25, 's_count' => 1, 'has_data_vers' => ['3.0'], 'next_s_cur_times' => 5],
                    ['gacha_type' => 'GACHA_TYPE_SOMETHING_NEW', 'total_count' => '3', 's_count' => '0', 'has_data_vers' => ['3.0']],
                ],
            ]),
            ['hist_pool_stat', '2'] => self::ok(['list' => str_contains($query['vers'], '1.4') ? [
                self::phase('1.4', 'GACHA_STATE_ENDED', [self::record('Agent Alpha', 'ItemType_Avatar', 90, 90, 1_720_000_000, false, '1001')]),
            ] : [
                self::phase('2.6', 'GACHA_STATE_ENDED', [self::record('Engine Beta', 'ItemType_Weapon', 150, 60, 1_740_000_000, true, '14001')]),
            ]]),
            ['cur_pool_stat', '2'] => self::ok(['cur_ver' => '3.0', 'list' => [
                self::phase('3.0', 'GACHA_STATE_ENDED', [self::record('Agent Gamma', 'ItemType_Avatar', 170, 20, 1_760_000_000, true, '1002')]),
                self::phase('3.0', 'GACHA_STATE_IN_PROGRESS', []),
                self::phase('3.1', 'GACHA_STATE_NOT_START', []),
            ]]),
            ['cur_pool_stat', '5'] => self::ok(['cur_ver' => '3.0', 'list' => [
                self::phase('3.0', 'GACHA_STATE_IN_PROGRESS', [self::record('Bangboo Delta', 'ItemType_Bangboo', 20, 20, 1_761_000_000, true, '54001')]),
            ]]),
            default => ['retcode' => -1, 'message' => 'unexpected request'],
        };
    }

    public function testReadsEveryBannerWithChunkedVersionsAndTheCurrentVersion(): void
    {
        $history = $this->createService(self::respond(...))->getSRankHistory($this->createAccount());

        // index, two hist_pool_stat chunks (10 + 2 past versions) and cur_pool_stat for the Exclusive Channel, nothing for the
        // empty rerun banner, only cur_pool_stat for the Bangboo Channel
        $this->assertSame(
            ['index', 'hist_pool_stat', 'hist_pool_stat', 'cur_pool_stat', 'cur_pool_stat'],
            array_column($this->requests, 'endpoint'),
        );
        $this->assertSame('2.7,2.6,2.5,2.4,2.3,2.2,2.1,2.0,1.7,1.6', $this->requests[1]['query']['vers']);
        $this->assertSame('1.5,1.4', $this->requests[2]['query']['vers'], 'The current version is never in a hist_pool_stat request');
        $this->assertSame(['2', '2', '2', '5'], array_map(static fn (array $request): string => $request['query']['gacha_type'], \array_slice($this->requests, 1)));
        foreach ($this->requests as $request) {
            $this->assertSame('1300000001', $request['query']['uid']);
            $this->assertSame('prod_gf_eu', $request['query']['region']);
            $this->assertSame('en-us', $request['query']['lang']);
            $cookie = implode('; ', $request['headers']['cookie'] ?? []);
            $this->assertStringContainsString('ltoken_v2=v2_test', $cookie);
            $this->assertStringNotContainsString('stoken', $cookie, 'The HoYoLAB cookie only');
            $this->assertArrayNotHasKey('ds', $request['headers'], 'No DS header');
        }

        $this->assertSame(['2', '12', '5'], array_map(static fn ($banner): string => $banner->bannerKey, $history->banners));
        $this->assertSame(['Unknown banner type "GACHA_TYPE_SOMETHING_NEW" in the Signal Search statistics: ignored.'], $history->warnings);

        $exclusive = $history->getBanner('2');
        $this->assertNotNull($exclusive);
        $this->assertSame([181, 3, 11], [$exclusive->totalPulls, $exclusive->sCount, $exclusive->currentPity]);
        $this->assertSame([150, 90, 170], array_map(static fn (SRankHistoryRecord $record): int => $record->pullNumber, $exclusive->records), 'In response order');

        $alpha = $exclusive->records[1];
        $this->assertSame('1001', $alpha->itemId);
        $this->assertSame('Agent Alpha', $alpha->name);
        $this->assertSame('Agents', $alpha->itemType);
        $this->assertSame(90, $alpha->pity);
        $this->assertFalse($alpha->isUp);
        // 1720000000 is 2024-07-03 09:46:40 UTC: 10:46:40 on the Europe server (UTC+1), stored as wall-clock digits in UTC
        $this->assertSame('2024-07-03 10:46:40', $alpha->pulledAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $alpha->pulledAt->getTimezone()->getName());
        $this->assertSame('W-Engines', $exclusive->records[0]->itemType);
        $this->assertTrue($exclusive->records[0]->isUp);

        $rerun = $history->getBanner('12');
        $this->assertSame([], $rerun?->records);
        $this->assertSame(0, $rerun?->totalPulls);

        $bangboo = $history->getBanner('5');
        $this->assertSame(25, $bangboo?->totalPulls);
        $this->assertSame('Bangboo', $bangboo?->records[0]->itemType);
        $this->assertSame('54001', $bangboo?->records[0]->itemId);
        $this->assertNull($history->getBanner('1'), 'A banner the source does not list');
    }

    public function testRequestsAreSpacedWhenAClockIsInjected(): void
    {
        $clock = new MockClock('2026-11-22 10:00:00 UTC');
        $this->createService(self::respond(...), $clock)->getSRankHistory($this->createAccount());

        $this->assertCount(5, $this->requests);
        $this->assertSame('2026-11-22 10:00:04', $clock->now()->format('Y-m-d H:i:s'), 'One pause between two requests');
    }

    public function testAnErrorRetcodeFailsTheWholeHistory(): void
    {
        $service = $this->createService(static fn (string $endpoint): array => $endpoint === 'index'
            ? self::respond('index', [])
            : ['retcode' => -400005, 'message' => 'Parameter Error', 'data' => null]);

        $this->expectException(RetrieveSRankHistoryException::class);
        $this->expectExceptionMessage('[-400005]');
        $service->getSRankHistory($this->createAccount());
    }

    public function testAnUnreadableRecordFailsTheWholeHistory(): void
    {
        $service = $this->createService(static function (string $endpoint, array $query): array {
            if ($endpoint === 'cur_pool_stat' && $query['gacha_type'] === '5') {
                $broken = self::record('Bangboo Delta', 'ItemType_Bangboo', 20, 20, 1_761_000_000, true);
                unset($broken['sort']);

                return self::ok(['list' => [self::phase('3.0', 'GACHA_STATE_IN_PROGRESS', [$broken])]]);
            }

            return self::respond($endpoint, $query);
        });

        $this->expectException(RetrieveSRankHistoryException::class);
        $service->getSRankHistory($this->createAccount());
    }

    public function testAnIndexWithoutBannerListFails(): void
    {
        $service = $this->createService(static fn (): array => self::ok(['cur_ver' => '3.0']));

        $this->expectException(RetrieveSRankHistoryException::class);
        $service->getSRankHistory($this->createAccount());
    }

    public function testAnIndexWithoutCurrentVersionFailsBeforeAnyOtherRequest(): void
    {
        $service = $this->createService(static function (string $endpoint, array $query): array {
            $body = self::respond($endpoint, $query);
            unset($body['data']['cur_ver']);

            return $body;
        });

        try {
            $service->getSRankHistory($this->createAccount());
            $this->fail('Expected a missing current version');
        } catch (RetrieveSRankHistoryException $e) {
            $this->assertSame('The Signal Search statistics have no current version ("cur_ver").', $e->getMessage());
        }
        $this->assertSame(['index'], array_column($this->requests, 'endpoint'));
    }

    public function testABannerTypeListedTwiceFails(): void
    {
        $service = $this->createService(static function (string $endpoint, array $query): array {
            $body = self::respond($endpoint, $query);
            if ($endpoint === 'index') {
                $body['data']['type_stats'][] = $body['data']['type_stats'][2];
            }

            return $body;
        });

        $this->expectException(RetrieveSRankHistoryException::class);
        $this->expectExceptionMessage('Banner type "GACHA_TYPE_BANGBOO" is listed twice in the Signal Search statistics.');
        $service->getSRankHistory($this->createAccount());
    }
}

<?php

namespace App\Tests\HoyoverseBundle\Service;

use App\GamesBundle\Model\Pull\PullOrigin;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Entity\HoyoversePull;
use App\HoyoverseBundle\Exception\RetrievePullsDataException;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Battle chronicle pulls (ChroniclePullTrait on ZenlessZoneZeroService): request, conversion to the same rows the
 * getGachaLog sync stores, and errors. Fixtures follow the shape of real responses with invented values.
 */
class ZenlessZoneZeroChroniclePullsTest extends TestCase
{
    private const string CHRONICLE_URL = 'https://sg-act-public-api.hoyolab.com/event/game_record_zzz/api/zzz/gacha_record';

    /** @var list<array{method: string, url: string, query: array<string, string>, headers: array<string, string>}> */
    private array $requests = [];

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    private static function chronicleBody(array $records, bool $hasMore = false): array
    {
        return ['retcode' => 0, 'message' => 'OK', 'data' => ['gacha_item_list' => $records, 'has_more' => $hasMore]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(string $id, string $itemType, int $itemId, string $name, string $rarity, string $date = '2026-11-07 21:05:10'): array
    {
        [$day, $time] = explode(' ', $date);
        [$year, $month, $dayOfMonth] = array_map('intval', explode('-', $day));
        [$hour, $minute, $second] = array_map('intval', explode(':', $time));

        return [
            'id' => $id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'item_name' => $name,
            'rarity' => $rarity,
            'date' => ['year' => $year, 'month' => $month, 'day' => $dayOfMonth, 'hour' => $hour, 'minute' => $minute, 'second' => $second],
        ];
    }

    /**
     * @param array<string, mixed> ...$bodies JSON bodies answered in order
     */
    private function createService(array ...$bodies): ZenlessZoneZeroService
    {
        $responses = array_map(static fn (array $body): MockResponse => new MockResponse(json_encode($body, JSON_THROW_ON_ERROR)), $bodies);

        $service = new ZenlessZoneZeroService(new NullLogger());
        $service->setHoyolabClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            $this->requests[] = ['method' => $method, 'url' => strtok($url, '?'), 'query' => $query, 'headers' => $headers];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        }));

        return $service;
    }

    private static function account(): RuntimeAccountData
    {
        $profile = (new HoyoverseGameProfile())
            ->setAccount((new HoyoverseAccount())->setCookie('ltoken_v2=v2_lt; ltuid_v2=111; ltmid_v2=mid111'))
            ->setGameId(ZenlessZoneZeroService::GAME_ID)
            ->setGameBiz(ZenlessZoneZeroService::GAME_BIZ)
            ->setGameUid('1500000042')
            ->setRegion('prod_gf_eu')
            ->setNickname('Proxy');

        // stoken_v2 is in the parsed cookie on purpose: the chronicle request must not send it
        $cookie = (new ParsedCookie())
            ->setLtokenV2('v2_lt')
            ->setLtuidV2('111')
            ->setLtmidV2('mid111')
            ->setStokenV2('v2_st');

        return (new RuntimeAccountData())->setParsedCookie($cookie)->setGameProfile($profile);
    }

    public function testFetchesAPageWithTheHoyolabCookiesOnly(): void
    {
        $service = $this->createService(self::chronicleBody([self::record('1795000000000012353', 'ITEM_TYPE_WEAPON', 12015, '[Cinder] Cobalt', 'B')]));

        $pulls = $service->fetchChroniclePullsPage(self::account(), '2');

        $this->assertCount(1, $pulls);
        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('GET', $request['method']);
        $this->assertSame(self::CHRONICLE_URL, $request['url']);
        $this->assertSame(['uid' => '1500000042', 'region' => 'prod_gf_eu', 'gacha_type' => 'GACHA_TYPE_CHARACTER_UP', 'end_id' => '0'], $request['query']);
        $this->assertStringContainsString('ltoken_v2=v2_lt', $request['headers']['cookie']);
        $this->assertStringContainsString('ltuid_v2=111', $request['headers']['cookie']);
        $this->assertStringContainsString('ltmid_v2=mid111', $request['headers']['cookie']);
        $this->assertStringNotContainsString('stoken', $request['headers']['cookie']);
        $this->assertArrayNotHasKey('ds', $request['headers']);
    }

    public function testConvertsEveryFieldLikeTheGachaLogSync(): void
    {
        $records = [
            self::record('1795000000000012553', 'ITEM_TYPE_AVATAR', 1191, 'Ellen', 'S', '2026-11-07 21:05:10'),
            self::record('1795000000000012453', 'ITEM_TYPE_BANGBOO', 53001, 'Penguinboo', 'A', '2026-11-07 21:05:10'),
            self::record('1795000000000012353', 'ITEM_TYPE_WEAPON', 12015, '[Cinder] Cobalt', 'B', '2026-10-31 08:00:59'),
        ];
        $account = self::account();

        $pulls = $this->createService(self::chronicleBody($records))->fetchChroniclePullsPage($account, '2');

        $expected = [
            ['1795000000000012553', 'Agents', '1191', 'Ellen', 4, '2026-11-07 21:05:10'],
            ['1795000000000012453', 'Bangboo', '53001', 'Penguinboo', 3, '2026-11-07 21:05:10'],
            ['1795000000000012353', 'W-Engines', '12015', '[Cinder] Cobalt', 2, '2026-10-31 08:00:59'],
        ];
        $this->assertCount(3, $pulls);
        foreach ($expected as $index => [$pullId, $itemType, $itemId, $name, $rarity, $pulledAt]) {
            $pull = $pulls[$index];
            $this->assertInstanceOf(HoyoversePull::class, $pull);
            $this->assertSame($pullId, $pull->getPullId());
            $this->assertSame('2', $pull->getGachaType());
            $this->assertSame('Exclusive Channel', $pull->getGachaName());
            $this->assertSame($itemType, $pull->getItemType());
            $this->assertSame($itemId, $pull->getItemId());
            $this->assertSame($name, $pull->getName());
            $this->assertSame($rarity, $pull->getRarity());
            $this->assertSame($pulledAt, $pull->getPulledAt()->format('Y-m-d H:i:s'));
            $this->assertSame('UTC', $pull->getPulledAt()->getTimezone()->getName());
            $this->assertSame($records[$index], $pull->getRawPayload());
            $this->assertSame($account->getGameProfile(), $pull->getGameProfile());
            $this->assertSame(PullOrigin::Recorded, $pull->getOrigin());
            $this->assertNull($pull->getDeclaredPity());
        }
    }

    /**
     * The same pull read from getGachaLog (HoyoversePullTrait with the real serializer and object mapper) and from the
     * battle chronicle gives the same row, raw_payload apart.
     */
    public function testBuildsTheSameRowAsGetGachaLogForTheSamePull(): void
    {
        $gachaLogRow = [
            'uid' => '1500000042', 'gacha_id' => '0', 'gacha_type' => '2', 'item_id' => '1191', 'count' => '1',
            'time' => '2026-11-07 21:05:10', 'name' => 'Ellen', 'lang' => 'en-us', 'item_type' => 'Agents',
            'rank_type' => '4', 'id' => '1795000000000012553',
        ];
        $service = $this->createService(
            ['retcode' => 0, 'message' => 'OK', 'data' => ['page' => '1', 'size' => '20', 'list' => [$gachaLogRow], 'region' => 'prod_gf_eu', 'region_time_zone' => 1]],
            self::chronicleBody([self::record('1795000000000012553', 'ITEM_TYPE_AVATAR', 1191, 'Ellen', 'S', '2026-11-07 21:05:10')]),
        );
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $service->setDenormalizer(new Serializer([
            new ArrayDenormalizer(),
            new DateTimeNormalizer(),
            new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory), null, new ReflectionExtractor()),
        ]));
        $service->setObjectMapper(new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor()));
        $account = self::account();

        [$fromGachaLog] = $service->fetchPullsPage($account->getGameProfile(), '2', 'test-authkey');
        [$fromChronicle] = $service->fetchChroniclePullsPage($account, '2');

        $row = static fn (HoyoversePull $pull): array => [
            'pull_id' => $pull->getPullId(),
            'gacha_type' => $pull->getGachaType(),
            'gacha_name' => $pull->getGachaName(),
            'item_type' => $pull->getItemType(),
            'item_id' => $pull->getItemId(),
            'name' => $pull->getName(),
            'rarity' => $pull->getRarity(),
            'pulled_at' => $pull->getPulledAt()->format('Y-m-d H:i:s e'),
            'origin' => $pull->getOrigin(),
        ];
        $this->assertSame($row($fromGachaLog), $row($fromChronicle));
        $this->assertSame('2026-11-07 21:05:10 UTC', $row($fromChronicle)['pulled_at']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function bannerProvider(): iterable
    {
        yield 'Exclusive Channel' => ['2', 'GACHA_TYPE_CHARACTER_UP', 'Exclusive Channel'];
        yield 'W-Engine Channel' => ['3', 'GACHA_TYPE_WEAPON_UP', 'W-Engine Channel'];
        yield 'Exclusive Rescreening' => ['12', 'GACHA_TYPE_CHARACTER_RETURN', 'Exclusive Rescreening'];
        yield 'W-Engine Reverberation' => ['13', 'GACHA_TYPE_WEAPON_RETURN', 'W-Engine Reverberation'];
        yield 'Standard Channel' => ['1', 'GACHA_TYPE_PERMANENT', 'Standard Channel'];
        yield 'Bangboo Channel' => ['5', 'GACHA_TYPE_BANGBOO', 'Bangboo Channel'];
    }

    #[DataProvider('bannerProvider')]
    public function testMapsEveryConfiguredBannerToItsChronicleType(string $gachaType, string $chronicleType, string $bannerName): void
    {
        $service = $this->createService(self::chronicleBody([self::record('1795000000000012553', 'ITEM_TYPE_AVATAR', 1191, 'Ellen', 'S')]));

        [$pull] = $service->fetchChroniclePullsPage(self::account(), $gachaType, '1795000000000099953');

        $this->assertSame($chronicleType, $this->requests[0]['query']['gacha_type']);
        $this->assertSame('1795000000000099953', $this->requests[0]['query']['end_id']);
        $this->assertSame($gachaType, $pull->getGachaType());
        $this->assertSame($bannerName, $pull->getGachaName());
    }

    public function testEveryBannerOfTheGachaConfigHasAChronicleType(): void
    {
        $service = new ZenlessZoneZeroService(new NullLogger());

        $this->assertEqualsCanonicalizing(array_keys($service->getGachaConfig()->getBannerTypes()), array_map('strval', array_keys($service->getChronicleGachaTypes())));
    }

    public function testDatesAreServerWallClockTimesEvenInTheEuropeanDstGap(): void
    {
        // 02:30 does not exist in Europe/Rome on 2026-03-29: the server time must be kept as is, tagged as UTC
        $service = $this->createService(self::chronicleBody([self::record('1780000000000000153', 'ITEM_TYPE_WEAPON', 12015, '[Cinder] Cobalt', 'B', '2026-03-29 02:30:00')]));

        [$pull] = $service->fetchChroniclePullsPage(self::account(), '2');

        $this->assertSame('2026-03-29 02:30:00', $pull->getPulledAt()->format('Y-m-d H:i:s'));
        $this->assertSame('+00:00', $pull->getPulledAt()->format('P'));
    }

    public function testAnEmptyPageReturnsNoPulls(): void
    {
        $service = $this->createService(self::chronicleBody([]));

        $this->assertSame([], $service->fetchChroniclePullsPage(self::account(), '5', '1760000000000000153'));
    }

    public function testUnknownItemTypesAreKeptAsReceived(): void
    {
        $service = $this->createService(self::chronicleBody([self::record('1795000000000012553', 'ITEM_TYPE_SOMETHING_NEW', 99001, 'New Thing', 'A')]));

        [$pull] = $service->fetchChroniclePullsPage(self::account(), '1');

        $this->assertSame('ITEM_TYPE_SOMETHING_NEW', $pull->getItemType());
        $this->assertSame(3, $pull->getRarity());
    }

    public function testAHoyolabRefusalThrowsWithTheRetcode(): void
    {
        $service = $this->createService(['retcode' => -100, 'message' => 'Please login', 'data' => null]);

        try {
            $service->fetchChroniclePullsPage(self::account(), '2');
            $this->fail('Expected RetrievePullsDataException to be thrown.');
        } catch (RetrievePullsDataException $e) {
            $this->assertSame(-100, $e->getHoyolabRetcode());
            $this->assertSame('API returned error retcode [-100]: Please login', $e->getMessage());
            $this->assertStringNotContainsString('v2_lt', $e->getMessage());
        }
    }

    public function testABannerWithoutChronicleTypeThrowsWithoutCallingHoyolab(): void
    {
        $service = $this->createService(self::chronicleBody([]));

        try {
            $service->fetchChroniclePullsPage(self::account(), '99');
            $this->fail('Expected RetrievePullsDataException to be thrown.');
        } catch (RetrievePullsDataException $e) {
            $this->assertSame('Banner 99 is not available in the HoYoLAB battle chronicle.', $e->getMessage());
        }
        $this->assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedRecordProvider(): iterable
    {
        $record = self::record('1795000000000012553', 'ITEM_TYPE_AVATAR', 1191, 'Ellen', 'S');

        yield 'unknown rarity' => [array_merge($record, ['rarity' => 'SS']), 'Unexpected rarity in HoYoLAB battle chronicle record 1795000000000012553 (banner 2).'];
        yield 'numeric rarity' => [array_merge($record, ['rarity' => 4]), 'Unexpected rarity in HoYoLAB battle chronicle record 1795000000000012553 (banner 2).'];
        yield 'invalid date' => [array_merge($record, ['date' => ['year' => 2026, 'month' => 13, 'day' => 1, 'hour' => 0, 'minute' => 0, 'second' => 0]]), 'Unexpected date in HoYoLAB battle chronicle record 1795000000000012553 (banner 2).'];
        yield 'missing date' => [array_diff_key($record, ['date' => true]), 'Unexpected date in HoYoLAB battle chronicle record 1795000000000012553 (banner 2).'];
        yield 'missing name' => [array_diff_key($record, ['item_name' => true]), 'Unexpected item_name in HoYoLAB battle chronicle record 1795000000000012553 (banner 2).'];
        yield 'missing id' => [array_diff_key($record, ['id' => true]), 'HoYoLAB battle chronicle returned a record without id (banner 2).'];
    }

    /**
     * @param array<string, mixed> $record
     */
    #[DataProvider('malformedRecordProvider')]
    public function testUnexpectedRecordsThrow(array $record, string $message): void
    {
        $service = $this->createService(self::chronicleBody([$record]));

        $this->expectException(RetrievePullsDataException::class);
        $this->expectExceptionMessage($message);

        $service->fetchChroniclePullsPage(self::account(), '2');
    }
}

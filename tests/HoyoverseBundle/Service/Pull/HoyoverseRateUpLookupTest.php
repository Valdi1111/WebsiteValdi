<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Service\Catalog\AbstractIrminsulCatalog;
use App\HoyoverseBundle\Service\Catalog\GiCatalog;
use App\HoyoverseBundle\Service\Catalog\HsrCatalog;
use App\HoyoverseBundle\Service\Catalog\ZzzCatalog;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\Pull\HoyoverseRateUpLookup;
use App\HoyoverseBundle\Service\Pull\HoyoverseRateUpLookupFactory;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class HoyoverseRateUpLookupTest extends TestCase
{
    private const string EU = '+01:00';
    private const string NA = '-05:00';
    private const string ASIA = '+08:00';

    private const string CHARACTER = AbstractIrminsulCatalog::CATEGORY_CHARACTER;
    private const string WEAPON = AbstractIrminsulCatalog::CATEGORY_WEAPON;

    // ---- Fixtures (reduced from the irminsul.gg data) ----

    /**
     * @return array<string, mixed>
     */
    private static function item(string $id, string $name, int $rarity, string $category = self::CHARACTER, ?string $displayName = null, ?string $release = null): array
    {
        return ['id' => $id, 'name' => $name, 'display_name' => $displayName ?? $name, 'rarity' => $rarity, 'category' => $category, 'release' => $release];
    }

    /**
     * @param array{0: string, 1: ?int} $start [at, offset]
     * @param array{0: string, 1: ?int} $end   [at, offset]
     * @param list<string>              $up
     * @param list<string>              $upA
     *
     * @return array<string, mixed>
     */
    private static function phase(int $id, string $category, string $version, array $start, array $end, array $up, array $upA = [], ?string $title = null): array
    {
        return [
            'id' => $id,
            'category' => $category,
            'version' => $version,
            'title' => $title,
            'start' => ['at' => $start[0], 'offset' => $start[1]],
            'end' => ['at' => $end[0], 'offset' => $end[1]],
            'up' => $up,
            'up_a' => $upA,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $phases
     */
    private static function fill(AbstractIrminsulCatalog $catalog, array $items, array $phases): AbstractIrminsulCatalog
    {
        $catalog->save([
            'fetched_at' => '2026-11-23T06:00:00+00:00',
            'items' => array_column($items, null, 'id'),
            'phases' => $phases,
        ]);

        return $catalog;
    }

    private static function genshinCatalog(): GiCatalog
    {
        $catalog = new GiCatalog(new ArrayAdapter());
        self::fill($catalog, [
            self::item('10000002', 'Ayaka', 5, displayName: 'Kamisato Ayaka'),
            self::item('10000003', 'Jean', 5),
            self::item('10000060', 'Yelan', 5),
            self::item('10000089', 'Furina', 5),
            self::item('10000136', 'Mitya', 5),
            self::item('10000014', 'Barbara', 4),
            self::item('10000032', 'Bennett', 4),
            self::item('10000045', 'Rosaria', 4),
            self::item('11509', 'Mistsplitter Reforged', 5, self::WEAPON),
            self::item('12405', 'Rainslasher', 4, self::WEAPON),
            self::item('15401', 'Favonius Warbow', 4, self::WEAPON),
        ], [
            // 2.6.2 ends at 14:59:59 UTC+8 while 2.7.1 starts at 11:00 UTC+8: four hours of overlap
            self::phase(10028, self::CHARACTER, '2.6.2', ['2022-04-19 18:00:00', null], ['2022-05-31 14:59:59', 8], ['10000002'], ['10000045']),
            self::phase(10029, self::CHARACTER, '2.7.1', ['2022-05-31 11:00:00', 8], ['2022-06-21 17:59:59', null], ['10000060'], ['10000014']),
            self::phase(10107, self::CHARACTER, '7.2.1', ['2026-11-04 11:00:00', 8], ['2026-11-24 17:59:59', null], ['10000136', '10000089'], ['10000045']),
            self::phase(20107, self::WEAPON, '7.2.1', ['2026-11-04 11:00:00', 8], ['2026-11-24 17:59:59', null], ['11509'], ['12405']),
        ]);

        return $catalog;
    }

    private static function hsrCatalog(): HsrCatalog
    {
        $catalog = new HsrCatalog(new ArrayAdapter());
        self::fill($catalog, [
            self::item('1005', 'Kafka', 5),
            self::item('1213', 'Dan Heng Imbibitor Lunae', 5, displayName: 'Dan Heng • Imbibitor Lunae'),
            self::item('1001', 'March 7th Preservation', 4, displayName: 'March 7th (Preservation)'),
            self::item('1009', 'Asta', 4),
            self::item('23015', 'Brighter Than the Sun', 5, self::WEAPON),
            self::item('21000', 'Post-Op Conversation', 4, self::WEAPON),
            self::item('21001', 'Good Night and Sleep Well', 4, self::WEAPON),
        ], [
            self::phase(10064, self::CHARACTER, '4.7.2', ['2026-12-02 12:00:00', null], ['2026-12-22 14:59:59', null], ['1213'], ['1009']),
            self::phase(20064, self::WEAPON, '4.7.2', ['2026-12-02 12:00:00', null], ['2026-12-22 14:59:59', null], ['23015'], ['21000']),
        ]);

        return $catalog;
    }

    private static function zzzCatalog(): ZzzCatalog
    {
        $catalog = new ZzzCatalog(new ArrayAdapter());
        self::fill($catalog, [
            self::item('1201', 'Harumasa', 5, displayName: 'Asaba Harumasa'),
            self::item('1411', 'Yuzuha', 5, displayName: 'Ukinami Yuzuha'),
            self::item('1481', 'Dialyn', 5),
            self::item('1371', 'Norma', 5),
            self::item('1651', 'Kafka', 5),
            self::item('1521', 'Alice', 5),
            self::item('1031', 'Nicole', 4, displayName: 'Nicole Demara'),
            self::item('1121', 'Ben', 4, displayName: 'Ben Bigger'),
            self::item('1311', 'Soukaku', 4),
            self::item('1491', 'Remielle', 5),
        ], [
            self::phase(10035, self::CHARACTER, '3.0.2', ['2026-07-08 12:00:00', null], ['2026-07-28 14:59:59', null], ['1371'], ['1121']),
            // 3.1.2 and its rescreening 3.1.3 open at the same second: reruns prefer the titled phase
            self::phase(10037, self::CHARACTER, '3.1.2', ['2026-08-19 12:00:00', null], ['2026-09-08 14:59:59', null], ['1491'], ['1031']),
            self::phase(10038, self::CHARACTER, '3.1.3', ['2026-08-19 12:00:00', null], ['2026-09-08 14:59:59', null], ['1481', '1411', '1201'], ['1311'], 'Exclusive Rescreening'),
            // Same start second: the phase of a pull is the lowest id
            self::phase(10044, self::CHARACTER, '3.4.3', ['2027-01-01 12:00:00', null], ['2027-01-21 14:59:59', null], ['1651']),
            self::phase(10045, self::CHARACTER, '3.4.2', ['2027-01-01 12:00:00', null], ['2027-01-12 14:59:59', null], ['1521']),
            self::phase(10050, self::CHARACTER, '3.10.1', ['2028-01-01 12:00:00', null], ['2028-01-21 14:59:59', null], ['1651'], ['1031']),
            self::phase(10051, self::CHARACTER, 'Luna II.1', ['2029-01-01 12:00:00', null], ['2029-01-21 14:59:59', null], ['1651'], ['1031']),
        ]);

        return $catalog;
    }

    private static function lookup(AbstractIrminsulCatalog $catalog, string $game, string $zone = self::EU): HoyoverseRateUpLookup
    {
        return new HoyoverseRateUpLookup($catalog, new \DateTimeZone($zone), $game);
    }

    /**
     * A repository row (HoyoversePullRepository::findBannerRows()), tagged with Europe/Rome as Doctrine hydrates the
     * stored server wall clock.
     *
     * @return array<string, mixed>
     */
    private static function row(string $gachaType, string $pulledAt, string $name, string $itemId = '', int $rarity = 5, ?bool $isUp = null): array
    {
        return [
            'gachaType' => $gachaType,
            'itemId' => $itemId,
            'name' => $name,
            'rarity' => $rarity,
            'pulledAt' => new \DateTimeImmutable($pulledAt, new \DateTimeZone('Europe/Rome')),
            'isUp' => $isUp,
        ];
    }

    private static function at(string $wallClock): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wallClock, new \DateTimeZone('Europe/Rome'));
    }

    /**
     * @param list<array<string, mixed>> $phases
     *
     * @return list<int>
     */
    private static function ids(array $phases): array
    {
        return array_column($phases, 'id');
    }

    // ---- Server time ----

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function serverZones(): iterable
    {
        yield 'Europe' => [self::EU, '2026-11-04 04:00:00', '2026-11-04 03:59:59'];
        yield 'America' => [self::NA, '2026-11-03 22:00:00', '2026-11-03 21:59:59'];
        yield 'Asia' => [self::ASIA, '2026-11-04 11:00:00', '2026-11-04 10:59:59'];
    }

    #[DataProvider('serverZones')]
    public function testAZonedStartIsReadAsTheServerWallClock(string $zone, string $start, string $justBefore): void
    {
        $lookup = self::lookup(self::genshinCatalog(), HoyoverseRateUpLookup::GAME_GI, $zone);
        $phase = $lookup->phaseFor('301', self::at($start));

        $this->assertNotNull($phase);
        $this->assertSame(10107, $phase['id'], 'The start second is part of the phase');
        // The end has no zone: it is already the local time of every server
        $this->assertSame(['start_at' => $start, 'end_at' => '2026-11-24 17:59:59'], $lookup->phaseWindow($phase));
        $this->assertSame([], $lookup->activePhases('301', self::at($justBefore)));
        $this->assertSame([10107], self::ids($lookup->activePhases('301', self::at('2026-11-24 17:59:59'))), 'The end second too');
        $this->assertSame([], $lookup->activePhases('301', self::at('2026-11-24 18:00:00')));

        $this->assertTrue($lookup->isUp(self::row('301', $start, 'Furina'), true, false));
        $this->assertNull($lookup->isUp(self::row('301', $justBefore, 'Furina'), true, false), 'Outside every phase');
    }

    public function testOverlappingPhasesAreAllActiveAndThePullBelongsToTheLatestStart(): void
    {
        // Europe: 2.7.1 starts at 04:00, 2.6.2 ends at 07:59:59 (server time)
        $lookup = self::lookup(self::genshinCatalog(), HoyoverseRateUpLookup::GAME_GI, self::EU);
        $at = '2022-05-31 07:32:46';

        $this->assertSame([10028, 10029], self::ids($lookup->activePhases('301', self::at($at))));
        $this->assertSame([10028, 10029], self::ids($lookup->activePhases('400', self::at($at))), '400 is a character banner too');
        $this->assertSame(10029, $lookup->phaseFor('301', self::at($at))['id'] ?? null);

        // The rate-ups of both phases count
        $this->assertTrue($lookup->isUp(self::row('301', $at, 'Yelan'), true, false));
        $this->assertTrue($lookup->isUp(self::row('400', $at, 'Ayaka'), true, false));
        $this->assertFalse($lookup->isUp(self::row('301', $at, 'Jean'), true, false));
        $this->assertTrue($lookup->isUp(self::row('301', $at, 'Rosaria', rarity: 4), false, true));
        $this->assertTrue($lookup->isUp(self::row('301', $at, 'Barbara', rarity: 4), false, true));

        $this->assertSame([10029], self::ids($lookup->activePhases('301', self::at('2022-05-31 08:00:00'))));
        $this->assertFalse($lookup->isUp(self::row('301', '2022-05-31 08:00:00', 'Ayaka'), true, false), '2.6.2 is over');

        // Asia: the overlap is 11:00 - 14:59:59
        $asia = self::lookup(self::genshinCatalog(), HoyoverseRateUpLookup::GAME_GI, self::ASIA);
        $this->assertSame([10028], self::ids($asia->activePhases('301', self::at($at))));
        $this->assertSame([10028, 10029], self::ids($asia->activePhases('301', self::at('2022-05-31 14:59:59'))));
    }

    // ---- Items ----

    public function testGenshinItemsAreFoundByNameAndAnUnknownNameStaysUnknown(): void
    {
        $lookup = self::lookup(self::genshinCatalog(), HoyoverseRateUpLookup::GAME_GI);
        $at = '2026-11-10 12:00:00';

        $this->assertTrue($lookup->isUp(self::row('301', $at, 'furina'), true, false), 'Case-insensitive');
        $this->assertTrue($lookup->isUp(self::row('301', $at, 'Mitya'), true, false), 'Second S rate-up of the phase');
        $this->assertFalse($lookup->isUp(self::row('301', $at, 'Jean'), true, false), 'Lost 50/50');
        $this->assertNull($lookup->isUp(self::row('301', $at, 'Unknown Hero'), true, false));
        $this->assertNull($lookup->isUp(self::row('301', $at, 'Unknown Hero', rarity: 4), false, true));
        $this->assertTrue($lookup->isUp(self::row('302', $at, 'Mistsplitter Reforged'), true, false));
        $this->assertFalse($lookup->isUp(self::row('302', $at, 'Furina'), true, false), 'Weapon phases only');
    }

    public function testHsrAndZzzItemsAreFoundByIdWhateverTheirName(): void
    {
        $hsr = self::lookup(self::hsrCatalog(), HoyoverseRateUpLookup::GAME_HSR);
        $at = '2026-12-10 12:00:00';
        $this->assertTrue($hsr->isUp(self::row('11', $at, 'Dan Heng • Imbibitor Lunae', '1213'), true, false));
        $this->assertTrue($hsr->isUp(self::row('11', $at, 'Renamed in game', '1213'), true, false), 'The id wins');
        $this->assertFalse($hsr->isUp(self::row('11', $at, 'Kafka', '1005'), true, false));
        $this->assertTrue($hsr->isUp(self::row('11', $at, 'Dan Heng Imbibitor Lunae', '99999'), true, false), 'Unknown id: by name');

        // ZZZ stores S as rarity 4 and A as 3: the flags only depend on the catalog lists
        $zzz = self::lookup(self::zzzCatalog(), HoyoverseRateUpLookup::GAME_ZZZ);
        $this->assertTrue($zzz->isUp(self::row('2', '2026-07-10 12:00:00', 'Norma', '1371', 4), true, false));
        $this->assertFalse($zzz->isUp(self::row('12', '2026-07-10 12:00:00', 'Kafka', '1651', 4), true, false));
    }

    // ---- Rules ----

    public function testAStoredFlagWinsAndOnlySAndARowsAreFlagged(): void
    {
        $lookup = self::lookup(self::genshinCatalog(), HoyoverseRateUpLookup::GAME_GI);
        $at = '2026-11-10 12:00:00';

        $this->assertFalse($lookup->isUp(self::row('301', $at, 'Furina', isUp: false), true, false));
        $this->assertTrue($lookup->isUp(self::row('301', $at, 'Jean', isUp: true), true, false));
        $this->assertTrue($lookup->isUp(self::row('200', $at, 'Jean', isUp: true), true, false), 'Even on a banner without phases');
        $this->assertTrue($lookup->isUp(self::row('301', $at, 'Cool Steel', rarity: 3, isUp: true), false, false));
        $this->assertNull($lookup->isUp(self::row('301', $at, 'Rosaria', rarity: 3), false, false), 'Neither S nor A');
    }

    public function testARules(): void
    {
        // Genshin: listed -> true, any other A (characters and weapons alike) -> false
        $gi = self::lookup(self::genshinCatalog(), HoyoverseRateUpLookup::GAME_GI);
        $at = '2026-11-10 12:00:00';
        $this->assertTrue($gi->isUp(self::row('301', $at, 'Rosaria', rarity: 4), false, true));
        $this->assertFalse($gi->isUp(self::row('301', $at, 'Bennett', rarity: 4), false, true));
        $this->assertFalse($gi->isUp(self::row('301', $at, 'Favonius Warbow', rarity: 4), false, true));
        $this->assertTrue($gi->isUp(self::row('302', $at, 'Rainslasher', rarity: 4), false, true));

        // HSR: character warp as Genshin; light cone warp lists are incomplete, so an unlisted A stays unknown
        $hsr = self::lookup(self::hsrCatalog(), HoyoverseRateUpLookup::GAME_HSR);
        $at = '2026-12-10 12:00:00';
        $this->assertTrue($hsr->isUp(self::row('11', $at, 'Asta', '1009', 4), false, true));
        $this->assertFalse($hsr->isUp(self::row('11', $at, 'March 7th', '1001', 4), false, true));
        $this->assertTrue($hsr->isUp(self::row('12', $at, 'Post-Op Conversation', '21000', 4), false, true));
        $this->assertNull($hsr->isUp(self::row('12', $at, 'Good Night and Sleep Well', '21001', 4), false, true));
        $this->assertFalse($hsr->isUp(self::row('12', $at, 'Kafka', '1005'), true, false), 'S rules unchanged on light cones');

        // ZZZ before 3.1: as Genshin
        $zzz = self::lookup(self::zzzCatalog(), HoyoverseRateUpLookup::GAME_ZZZ);
        $this->assertTrue($zzz->isUp(self::row('2', '2026-07-10 12:00:00', 'Ben', '1121', 3), false, true));
        $this->assertFalse($zzz->isUp(self::row('2', '2026-07-10 12:00:00', 'Nicole', '1031', 3), false, true));
        // Since 3.1 the player picks the A rate-ups: unknown even when listed (3.10 is after 3.1)
        $this->assertNull($zzz->isUp(self::row('12', '2026-08-20 12:00:00', 'Soukaku', '1311', 3), false, true));
        $this->assertNull($zzz->isUp(self::row('2', '2026-08-20 12:00:00', 'Nicole', '1031', 3), false, true));
        $this->assertNull($zzz->isUp(self::row('2', '2028-01-10 12:00:00', 'Nicole', '1031', 3), false, true));
        $this->assertTrue($zzz->isUp(self::row('2', '2026-08-20 12:00:00', 'Dialyn', '1481', 4), true, false), 'S rules unchanged');
        // A version without major.minor numbers is not read as 3.1 or later
        $this->assertTrue($zzz->isUp(self::row('2', '2029-01-10 12:00:00', 'Nicole', '1031', 3), false, true));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function typesWithoutPhases(): iterable
    {
        yield 'Genshin standard, beginner, chronicled' => [HoyoverseRateUpLookup::GAME_GI, ['200', '100', '500', '']];
        yield 'HSR regular, departure, collaborations' => [HoyoverseRateUpLookup::GAME_HSR, ['1', '2', '21', '22']];
        yield 'ZZZ standard, bangboo' => [HoyoverseRateUpLookup::GAME_ZZZ, ['1', '5', '301']];
    }

    /**
     * @param list<string> $gachaTypes
     */
    #[DataProvider('typesWithoutPhases')]
    public function testTypesOutsideTheTableHaveNoPhaseAndStayUnknown(string $game, array $gachaTypes): void
    {
        $catalog = match ($game) {
            HoyoverseRateUpLookup::GAME_GI => self::genshinCatalog(),
            HoyoverseRateUpLookup::GAME_HSR => self::hsrCatalog(),
            default => self::zzzCatalog(),
        };
        $lookup = self::lookup($catalog, $game);

        foreach (['2026-11-10 12:00:00', '2026-12-10 12:00:00', '2026-07-10 12:00:00'] as $at) {
            foreach ($gachaTypes as $gachaType) {
                $this->assertNull($lookup->categoryFor($gachaType));
                $this->assertSame([], $lookup->activePhases($gachaType, self::at($at)));
                $this->assertNull($lookup->phaseFor($gachaType, self::at($at)));
                $this->assertNull($lookup->isUp(self::row($gachaType, $at, 'Furina', '1213'), true, false));
                $this->assertNull($lookup->isUp(self::row($gachaType, $at, 'Rosaria', '1009', 4), false, true));
            }
        }
    }

    public function testGachaTypeCategories(): void
    {
        $gi = self::lookup(self::genshinCatalog(), HoyoverseRateUpLookup::GAME_GI);
        $this->assertSame([self::CHARACTER, self::CHARACTER, self::WEAPON], [$gi->categoryFor('301'), $gi->categoryFor('400'), $gi->categoryFor('302')]);
        $hsr = self::lookup(self::hsrCatalog(), HoyoverseRateUpLookup::GAME_HSR);
        $this->assertSame([self::CHARACTER, self::WEAPON], [$hsr->categoryFor('11'), $hsr->categoryFor('12')]);
        $zzz = self::lookup(self::zzzCatalog(), HoyoverseRateUpLookup::GAME_ZZZ);
        $this->assertSame(
            [self::CHARACTER, self::CHARACTER, self::WEAPON, self::WEAPON],
            [$zzz->categoryFor('2'), $zzz->categoryFor('12'), $zzz->categoryFor('3'), $zzz->categoryFor('13')],
        );

        $this->expectException(\InvalidArgumentException::class);
        self::lookup(self::genshinCatalog(), 'hi3');
    }

    // ---- Per-phase breakdown ----

    public function testEqualStartsGiveTheLowestPhaseIdAndLabelsFollowTheRecordNames(): void
    {
        $lookup = self::lookup(self::zzzCatalog(), HoyoverseRateUpLookup::GAME_ZZZ);

        $at = self::at('2027-01-05 12:00:00');
        $this->assertSame([10044, 10045], self::ids($lookup->activePhases('2', $at)));
        $this->assertSame(10044, $lookup->phaseFor('2', $at)['id'] ?? null);
        $this->assertSame(10044, $lookup->phaseFor('12', self::at('2027-01-01 12:00:00'))['id'] ?? null, 'From the shared start second');

        $this->assertSame(10037, $lookup->phaseFor('2', self::at('2026-08-20 12:00:00'))['id'] ?? null, 'The regular channel keeps the untitled phase');
        $rescreening = $lookup->phaseFor('12', self::at('2026-08-20 12:00:00'));
        $this->assertNotNull($rescreening);
        $this->assertSame(10038, $rescreening['id'], 'The rescreening channel takes the titled phase on the shared start');
        // ZZZ records use the short names ("Yuzuha"), Genshin and HSR the full display names
        $this->assertSame('3.1.3 · Exclusive Rescreening · Dialyn, Yuzuha, Harumasa', $lookup->phaseLabel($rescreening));
        // Featured items in the shape of a pull row of the game, for the icon pipeline (ZZZ: game id, record type)
        $this->assertSame([
            ['item_id' => '1481', 'name' => 'Dialyn', 'item_type' => 'Agents', 'rarity' => 4],
            ['item_id' => '1411', 'name' => 'Yuzuha', 'item_type' => 'Agents', 'rarity' => 4],
            ['item_id' => '1201', 'name' => 'Harumasa', 'item_type' => 'Agents', 'rarity' => 4],
        ], $lookup->phaseFeatured($rescreening));
        $this->assertSame(['start_at' => '2026-08-19 12:00:00', 'end_at' => '2026-09-08 14:59:59'], $lookup->phaseWindow($rescreening));

        $gi = self::lookup(self::genshinCatalog(), HoyoverseRateUpLookup::GAME_GI);
        $phase = $gi->phaseFor('301', self::at('2026-11-10 12:00:00'));
        $this->assertNotNull($phase);
        $this->assertSame('7.2.1 · Mitya, Furina', $gi->phaseLabel($phase));
    }

    // ---- Factory ----

    public function testTheFactoryPicksTheGameCatalogAndTheServerZone(): void
    {
        $factory = new HoyoverseRateUpLookupFactory(
            self::genshinCatalog(),
            new HsrCatalog(new ArrayAdapter()),
            self::zzzCatalog(),
            new HoyoverseServerTimezoneResolver(),
        );

        $america = $factory->forProfile(self::profile(GenshinImpactService::GAME_ID, 'os_usa'));
        $this->assertNotNull($america);
        $phase = $america->phaseFor('301', self::at('2026-11-03 22:00:00'));
        $this->assertSame(['start_at' => '2026-11-03 22:00:00', 'end_at' => '2026-11-24 17:59:59'], $phase !== null ? $america->phaseWindow($phase) : null);

        $zzz = $factory->forProfile(self::profile(ZenlessZoneZeroService::GAME_ID, 'prod_gf_eu'));
        $this->assertNotNull($zzz);
        $this->assertSame(self::CHARACTER, $zzz->categoryFor('12'), 'The ZZZ gacha types');

        $this->assertNull($factory->forProfile(self::profile(HonkaiStarRailService::GAME_ID, 'prod_official_eur')), 'Catalog not built yet');
        $this->assertNull($factory->forProfile(self::profile(HonkaiImpact3rdService::GAME_ID, 'eur01')), 'No catalog for the game');
    }

    private static function profile(int $gameId, string $region): HoyoverseGameProfile
    {
        return (new HoyoverseGameProfile())->setGameId($gameId)->setRegion($region);
    }

    public function testAnHsrACharacterOnTheLightConeWarpIsAMiss(): void
    {
        $hsr = self::lookup(self::hsrCatalog(), HoyoverseRateUpLookup::GAME_HSR);
        $at = '2026-12-10 12:00:00';

        // Characters are never featured on the light cone warp: a definite miss, not unknown
        $this->assertFalse($hsr->isUp(self::row('12', $at, 'March 7th (Preservation)', '1001', 4), false, true));
        // An unlisted A light cone stays unknown (irminsul's A light cone lists are incomplete)
        $this->assertNull($hsr->isUp(self::row('12', $at, 'Good Night and Sleep Well', '21001', 4), false, true));
        $this->assertTrue($hsr->isUp(self::row('12', $at, 'Post-Op Conversation', '21000', 4), false, true));
    }

    public function testUntitledZzzRerunsAreToldByTheReleaseOfTheirFeaturedItems(): void
    {
        $catalog = new ZzzCatalog(new ArrayAdapter());
        self::fill($catalog, [
            self::item('1521', 'Alice', 5, release: '2.1'),
            self::item('1381', 'Astra', 5, release: '1.5'),
            self::item('1661', 'Sunbringer', 5, release: '3.4'),
            self::item('1651', 'Kafka', 5, release: '3.4'),
        ], [
            // A new banner without A rate-ups listed (irminsul leaves them out on recent phases)
            self::phase(10043, self::CHARACTER, '3.4.1', ['2026-12-02 11:00:00', 8], ['2027-01-12 14:59:59', null], ['1661']),
            // Same start: a new banner (Kafka, released in 3.4) and an untitled rescreening (only older S)
            self::phase(10044, self::CHARACTER, '3.4.3', ['2027-01-01 12:00:00', null], ['2027-01-21 14:59:59', null], ['1651']),
            self::phase(10045, self::CHARACTER, '3.4.2', ['2027-01-01 12:00:00', null], ['2027-01-12 14:59:59', null], ['1521', '1381']),
        ]);
        $lookup = self::lookup($catalog, HoyoverseRateUpLookup::GAME_ZZZ);
        $at = self::at('2027-01-02 12:00:00');

        $this->assertSame(10045, $lookup->phaseFor('12', $at)['id'] ?? null, 'The rescreening channel takes the phase of older S only');
        $this->assertSame(10044, $lookup->phaseFor('2', $at)['id'] ?? null, 'The regular channel takes the latest new banner');
        // The rate-up flags still read every running phase
        $this->assertTrue($lookup->isUp(self::row('2', '2027-01-02 12:00:00', 'Alice', '1521', 4), true, false));
    }
}

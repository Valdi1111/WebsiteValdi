<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Pull\Card\PullCardItem;
use App\GamesBundle\Model\Pull\Card\PullCardSection;
use App\GamesBundle\Service\Pull\Card\PullCardSectionFactory;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Repository\HoyoversePullRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\Pull\HoyoversePullCardBuilder;
use App\HoyoverseBundle\Service\Pull\HoyoversePullStatsCalculator;
use App\HoyoverseBundle\Service\Pull\HoyoverseRateUpLookupFactory;
use App\HoyoverseBundle\Service\Pull\HoyoverseServerTimezoneResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Content of the shareable pulls card of a HoYoverse profile, over the real stats calculator.
 */
class HoyoversePullCardBuilderTest extends TestCase
{
    private const string CLOCK_NOW = '2026-11-27 08:30:00';
    private const string CHARACTER_BANNER = '301';

    private int $nextId = 1;

    private function createProfile(?int $gameId = GenshinImpactService::GAME_ID): HoyoverseGameProfile
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $account = (new HoyoverseAccount())->setUser($user);
        $profile = (new HoyoverseGameProfile())
            ->setAccount($account)
            ->setGameId($gameId)
            ->setGameBiz(GenshinImpactService::GAME_BIZ)
            ->setRegion('os_euro')
            ->setGameUid('700000001')
            ->setNickname('Traveler');
        (new \ReflectionProperty(HoyoverseGameProfile::class, 'id'))->setValue($profile, 10);

        return $profile;
    }

    private function createBuilder(HoyoversePullRepository $pullRepo): HoyoversePullCardBuilder
    {
        $genshin = new GenshinImpactService(new NullLogger());
        $honkaiImpact = new HonkaiImpact3rdService(new NullLogger());
        $locator = new ServiceLocator([
            GenshinImpactService::GAME_ID => static fn () => $genshin,
            HonkaiImpact3rdService::GAME_ID => static fn () => $honkaiImpact,
        ]);

        // Server "now" of the gap planner, different from the clock of generatedAt
        $timezoneResolver = $this->createStub(HoyoverseServerTimezoneResolver::class);
        $timezoneResolver->method('getServerWallClockNow')->willReturn(new \DateTimeImmutable('2026-11-22 12:00:00', new \DateTimeZone('UTC')));
        // No catalog: the rate-up flags stay as stored on the rows
        $rateUpLookups = $this->createStub(HoyoverseRateUpLookupFactory::class);
        $rateUpLookups->method('forProfile')->willReturn(null);

        $iconUrlBuilder = $this->createStub(PullItemIconUrlBuilder::class);
        $iconUrlBuilder->method('buildRowPath')->willReturnCallback(
            static fn (string|object $gameService, array $row): string => 'icons/'.$row['item_id'].'.webp'
        );

        return new HoyoversePullCardBuilder(
            $locator,
            $pullRepo,
            new HoyoversePullStatsCalculator(new PullGapPlanner()),
            $timezoneResolver,
            $rateUpLookups,
            new PullCardSectionFactory($iconUrlBuilder),
            new MockClock(self::CLOCK_NOW.' UTC'),
        );
    }

    /**
     * A row as returned by HoyoversePullRepository::findProfileRows() / findBannerRows(), one minute after the previous one.
     *
     * @return array<string, mixed>
     */
    private function pullRow(string $gachaType, int $rarity, string $name, ?bool $isUp = null, string $gachaName = 'Character Event Wish'): array
    {
        $id = $this->nextId++;

        return [
            'id' => $id,
            'pullId' => sprintf('17000000000000%05d', $id),
            'gachaType' => $gachaType,
            'gachaName' => $gachaName,
            'itemType' => $rarity >= 5 ? 'Character' : 'Weapon',
            'itemId' => strtolower(str_replace(' ', '_', $name)),
            'name' => $name,
            'rarity' => $rarity,
            'isUp' => $isUp,
            'pulledAt' => new \DateTimeImmutable('2026-06-01 10:00:00')->modify(sprintf('+%d minutes', $id)),
        ];
    }

    /**
     * $sCount S pulls, oldest first, each one right after a 3-star (pity 2), named "<prefix> 1" (oldest) to "<prefix> N".
     *
     * @param array<int, bool> $isUp stored rate-up flag by S number (1 = oldest), unknown for the others
     *
     * @return list<array<string, mixed>>
     */
    private function bannerRows(string $gachaType, int $sCount, string $prefix, array $isUp = [], string $gachaName = 'Character Event Wish'): array
    {
        $rows = [];
        for ($i = 1; $i <= $sCount; ++$i) {
            $rows[] = $this->pullRow($gachaType, 3, 'Debate Club', gachaName: $gachaName);
            $rows[] = $this->pullRow($gachaType, 5, $prefix.' '.$i, $isUp[$i] ?? null, $gachaName);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function itemNames(PullCardSection $section): array
    {
        return array_map(static fn (PullCardItem $item): string => $item->name, $section->items);
    }

    public function testOneBannerShowsItsNewestSPullsAndCountsTheRateUpOfAllOfThem(): void
    {
        $profile = $this->createProfile();
        // The 9 oldest S stay off the card: the first is a lost 50/50, the next nine won
        $isUp = [1 => false] + array_fill(2, 9, true);
        $rows = $this->bannerRows(self::CHARACTER_BANNER, 40, 'Char', $isUp);

        $pullRepo = $this->createMock(HoyoversePullRepository::class);
        $pullRepo->expects($this->once())->method('findBannerRows')->with($profile, ['301', '400'])->willReturn($rows);
        $pullRepo->expects($this->never())->method('findProfileRows');

        $card = $this->createBuilder($pullRepo)->build($profile, self::CHARACTER_BANNER);

        $this->assertSame('Genshin Impact', $card->gameName);
        $this->assertSame('Character Event Wish', $card->title, 'The title is the banner group name');
        $this->assertSame('5-Star', $card->sLabel, 'The long rarity label');
        $this->assertSame('Wishes', $card->pullLabel);
        $this->assertCount(1, $card->sections);

        $section = $card->sections[0];
        $this->assertSame('Character Event Wish', $section->name);
        $this->assertSame(80, $section->totalPulls);
        $this->assertSame(40, $section->sCount);
        $this->assertSame(2.0, $section->avgSPity);
        $this->assertSame(0, $section->sPity);
        $this->assertSame(90, $section->sMaxPity);
        $this->assertNull($section->pullLabel, 'HoYoverse banners use the unit of the game');

        $this->assertCount(HoyoversePullCardBuilder::BANNER_LIMIT, $section->items);
        $this->assertSame(9, $section->hiddenCount);
        $this->assertSame('Char 40', $section->items[0]->name, 'Newest first');
        $this->assertSame('Char 10', $section->items[30]->name, 'The oldest one shown');
        $this->assertSame(2, $section->items[0]->pity);
        $this->assertNull($section->items[0]->isUp);
        $this->assertTrue($section->items[30]->isUp);
        $this->assertSame('icons/char_40.webp', $section->items[0]->iconPath);
        $this->assertSame(10, $section->upKnown, 'Hidden S count too');
        $this->assertSame(9, $section->upCount);
    }

    public function testOneBannerWithShowAllListsEverySPull(): void
    {
        $profile = $this->createProfile();
        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('findBannerRows')->willReturn($this->bannerRows(self::CHARACTER_BANNER, 40, 'Char'));

        $section = $this->createBuilder($pullRepo)->build($profile, self::CHARACTER_BANNER, showAll: true)->sections[0];

        $this->assertCount(40, $section->items);
        $this->assertSame(0, $section->hiddenCount);
        $this->assertSame('Char 40', $section->items[0]->name);
        $this->assertSame('Char 1', $section->items[39]->name);
    }

    public function testAllBannersKeepOnlyTheBannersWithPullsAndShowFewerSPullsEach(): void
    {
        $profile = $this->createProfile();
        $rows = [
            ...$this->bannerRows(self::CHARACTER_BANNER, 20, 'Char'),
            ...$this->bannerRows('200', 2, 'Std', gachaName: 'Standard Wish'),
            // A gacha type missing from the configuration becomes a banner of its own
            ...$this->bannerRows('999', 1, 'Mystery', gachaName: 'Mystery Wish'),
        ];

        $pullRepo = $this->createMock(HoyoversePullRepository::class);
        $pullRepo->expects($this->exactly(2))->method('findProfileRows')->with($profile)->willReturn($rows);
        $pullRepo->expects($this->never())->method('findBannerRows');
        $builder = $this->createBuilder($pullRepo);

        $card = $builder->build($profile);

        $this->assertSame('All banners', $card->title);
        $this->assertSame('Genshin Impact', $card->gameName);
        $this->assertSame(
            ['Character Event Wish', 'Standard Wish', 'Mystery Wish'],
            array_map(static fn (PullCardSection $section): string => $section->name, $card->sections),
            'The weapon, beginner and chronicled banners have no pulls',
        );
        $this->assertSame([40, 4, 2], array_map(static fn (PullCardSection $section): int => $section->totalPulls, $card->sections));
        $this->assertCount(HoyoversePullCardBuilder::PROFILE_LIMIT, $card->sections[0]->items);
        $this->assertSame(5, $card->sections[0]->hiddenCount);
        $this->assertSame('Char 20', $card->sections[0]->items[0]->name);
        $this->assertSame(['Std 2', 'Std 1'], self::itemNames($card->sections[1]));
        $this->assertSame(0, $card->sections[1]->hiddenCount);
        $this->assertSame(['Mystery 1'], self::itemNames($card->sections[2]));

        $all = $builder->build($profile, showAll: true);
        $this->assertCount(20, $all->sections[0]->items);
        $this->assertSame(0, $all->sections[0]->hiddenCount);
    }

    public function testUnknownBannerIsRefused(): void
    {
        $pullRepo = $this->createMock(HoyoversePullRepository::class);
        $pullRepo->expects($this->never())->method('findBannerRows');

        $this->expectException(\InvalidArgumentException::class);
        // 400 is a gacha type of the 301 group, not a banner key
        $this->expectExceptionMessage('Unknown banner "400".');

        $this->createBuilder($pullRepo)->build($this->createProfile(), '400');
    }

    public function testAnUnconfiguredGachaTypeIsABannerOfItsOwn(): void
    {
        $profile = $this->createProfile();
        $rows = [
            ...$this->bannerRows(self::CHARACTER_BANNER, 2, 'Char'),
            // Listed by the overview as a banner of its own, so the share dialog offers it
            ...$this->bannerRows('999', 1, 'Mystery', gachaName: 'Mystery Wish'),
        ];

        $pullRepo = $this->createMock(HoyoversePullRepository::class);
        $pullRepo->expects($this->once())->method('findProfileRows')->with($profile)->willReturn($rows);
        $pullRepo->expects($this->never())->method('findBannerRows');

        $card = $this->createBuilder($pullRepo)->build($profile, '999');

        $this->assertSame('Mystery Wish', $card->title);
        $this->assertCount(1, $card->sections);
        $this->assertSame(['Mystery 1'], self::itemNames($card->sections[0]));
    }

    /**
     * @return iterable<string, array{?int}>
     */
    public static function gamesWithoutPullTracker(): iterable
    {
        yield 'Honkai Impact 3rd' => [HonkaiImpact3rdService::GAME_ID];
        yield 'unknown game' => [99];
        yield 'no game' => [null];
    }

    #[DataProvider('gamesWithoutPullTracker')]
    public function testProfileOfAGameWithoutPullTrackerIsRefused(?int $gameId): void
    {
        $pullRepo = $this->createMock(HoyoversePullRepository::class);
        $pullRepo->expects($this->never())->method('findProfileRows');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This game profile does not support pull tracking.');

        $this->createBuilder($pullRepo)->build($this->createProfile($gameId));
    }

    public function testPlayerNameAndUidAppearOnlyWhenAsked(): void
    {
        $profile = $this->createProfile();
        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('findProfileRows')->willReturn([]);
        $builder = $this->createBuilder($pullRepo);

        $hidden = $builder->build($profile);
        $this->assertNull($hidden->playerName);
        $this->assertNull($hidden->playerUid);
        $this->assertSame([], $hidden->sections, 'No banner has pulls');

        $shown = $builder->build($profile, showPlayer: true);
        $this->assertSame('Traveler', $shown->playerName);
        $this->assertSame('700000001', $shown->playerUid);
    }

    public function testGeneratedAtComesFromTheClock(): void
    {
        $pullRepo = $this->createStub(HoyoversePullRepository::class);
        $pullRepo->method('findProfileRows')->willReturn([]);

        $card = $this->createBuilder($pullRepo)->build($this->createProfile());

        $this->assertSame(self::CLOCK_NOW, $card->generatedAt->format('Y-m-d H:i:s'), 'Not the server wall clock of the planner');
    }
}

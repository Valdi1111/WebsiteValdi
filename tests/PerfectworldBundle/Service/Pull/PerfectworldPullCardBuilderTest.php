<?php

namespace App\Tests\PerfectworldBundle\Service\Pull;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Pull\Card\PullCardItem;
use App\GamesBundle\Model\Pull\Card\PullCardSection;
use App\GamesBundle\Model\Pull\PullOrigin;
use App\GamesBundle\Service\Pull\Card\PullCardSectionFactory;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Entity\PerfectworldPull;
use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Repository\PerfectworldPullRepository;
use App\PerfectworldBundle\Service\Catalog\NteCatalog;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullCardBuilder;
use App\PerfectworldBundle\Service\Pull\PerfectworldPullStatsCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Content of the shareable pulls card of a Perfect World profile, over the real stats calculator.
 */
class PerfectworldPullCardBuilderTest extends TestCase
{
    private const string CLOCK_NOW = '2026-11-27 08:30:00';
    private const string LIMITED = NevernessToEvernessService::BANNER_LIMITED;
    private const string STANDARD = NevernessToEvernessService::BANNER_STANDARD;
    private const string ARC = NevernessToEvernessService::BANNER_ARC;

    private int $nextId = 1;

    private function createProfile(int $gameId = NevernessToEvernessService::GAME_ID): PerfectworldGameProfile
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $account = (new PerfectworldAccount())->setUser($user);
        $profile = (new PerfectworldGameProfile())->setGameId($gameId)->setGameUid('100000000001')->setNickname('Player_1');
        $account->addGameProfile($profile);
        (new \ReflectionProperty(PerfectworldGameProfile::class, 'id'))->setValue($profile, 5);

        return $profile;
    }

    private function createBuilder(PerfectworldPullRepository $pullRepo): PerfectworldPullCardBuilder
    {
        $nte = new NevernessToEvernessService(new NullLogger());
        // A Perfect World game without pull tracker
        $otherGame = $this->createStub(GameInterface::class);
        $locator = new ServiceLocator([
            (string) NevernessToEvernessService::GAME_ID => static fn () => $nte,
            '2' => static fn () => $otherGame,
        ]);

        // No rotation names: the items keep the name of their banner group
        $catalog = $this->createStub(NteCatalog::class);
        $catalog->method('findPoolName')->willReturn(null);

        $iconUrlBuilder = $this->createStub(PullItemIconUrlBuilder::class);
        $iconUrlBuilder->method('buildRowPath')->willReturnCallback(
            static fn (string|object $gameService, array $row): string => 'icons/'.$row['item_id'].'.webp'
        );

        return new PerfectworldPullCardBuilder(
            $locator,
            $pullRepo,
            new PerfectworldPullStatsCalculator(new PullGapPlanner()),
            $catalog,
            new PullCardSectionFactory($iconUrlBuilder),
            new MockClock(self::CLOCK_NOW.' UTC'),
        );
    }

    /**
     * A row as returned by PerfectworldPullRepository::findBannerRows(), one minute after the previous one.
     *
     * @return array<string, mixed>
     */
    private function pullRow(string $kind, string $rewardType, int $rarity, string $name, ?bool $isUp = null): array
    {
        $id = $this->nextId++;

        return [
            'id' => $id,
            'bannerKey' => 'x',
            'poolId' => null,
            'pulledAt' => new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC'))->modify(sprintf('+%d minutes', $id)),
            'groupPosition' => 0,
            'kind' => $kind,
            'rollResult' => $kind === PerfectworldPull::KIND_ROLL ? 3 : null,
            'rewardType' => $rewardType,
            'rewardId' => strtolower(str_replace(' ', '_', $name)),
            'name' => $name,
            'rarity' => $rarity,
            'quantity' => 1,
            'origin' => PullOrigin::Recorded,
            'declaredPity' => null,
            'isUp' => $isUp,
        ];
    }

    /**
     * $sCount S Arcs of the Arc banner, oldest first, each one right after a B Arc (pity 2), named "Arc 1" (oldest)
     * to "Arc N".
     *
     * @param array<int, bool> $isUp stored rate-up flag by S number (1 = oldest), unknown for the others
     *
     * @return list<array<string, mixed>>
     */
    private function arcRows(int $sCount, array $isUp = []): array
    {
        $rows = [];
        for ($i = 1; $i <= $sCount; ++$i) {
            $rows[] = $this->pullRow(PerfectworldPull::KIND_DRAW, 'arc', NevernessToEvernessService::RARITY_B, 'Be Happy');
            $rows[] = $this->pullRow(PerfectworldPull::KIND_DRAW, 'arc', NevernessToEvernessService::RARITY_S, 'Arc '.$i, $isUp[$i] ?? null);
        }

        return $rows;
    }

    /**
     * $sCount S characters rolled on a board, oldest first, each one on the roll after a B Arc (pity 2), named
     * "<prefix> 1" (oldest) to "<prefix> N".
     *
     * @return list<array<string, mixed>>
     */
    private function boardRows(int $sCount, string $prefix): array
    {
        $rows = [];
        for ($i = 1; $i <= $sCount; ++$i) {
            $rows[] = $this->pullRow(PerfectworldPull::KIND_ROLL, 'arc', NevernessToEvernessService::RARITY_B, 'Be Happy');
            $rows[] = $this->pullRow(PerfectworldPull::KIND_ROLL, 'character', NevernessToEvernessService::RARITY_S, $prefix.' '.$i);
        }

        return $rows;
    }

    /**
     * Rate-up flag of every S Arc: one lost 50/50 out of four.
     *
     * @return array<int, bool>
     */
    private static function everyFourthLost(int $sCount): array
    {
        $isUp = [];
        for ($i = 1; $i <= $sCount; ++$i) {
            $isUp[$i] = $i % 4 !== 0;
        }

        return $isUp;
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

        $pullRepo = $this->createMock(PerfectworldPullRepository::class);
        $pullRepo->expects($this->once())
            ->method('findBannerRows')
            ->with($profile, self::ARC)
            ->willReturn($this->arcRows(60, self::everyFourthLost(60)));

        $card = $this->createBuilder($pullRepo)->build($profile, self::ARC);

        $this->assertSame('Neverness to Everness', $card->gameName);
        $this->assertSame('Arc Research Program', $card->title, 'The title is the banner group name');
        $this->assertSame('S-Rank', $card->sLabel, 'The long rarity label');
        $this->assertSame('Pulls', $card->pullLabel, 'The generic unit of the game');
        $this->assertCount(1, $card->sections);

        $section = $card->sections[0];
        $this->assertSame('Arc Research Program', $section->name);
        $this->assertSame('Arcs', $section->pullLabel, 'The unit of the banner');
        $this->assertSame(120, $section->totalPulls);
        $this->assertSame(60, $section->sCount);
        $this->assertSame(2.0, $section->avgSPity);
        $this->assertSame(0, $section->sPity);
        $this->assertSame(60, $section->sMaxPity);

        $this->assertCount(PerfectworldPullCardBuilder::BANNER_LIMIT, $section->items);
        $this->assertSame(29, $section->hiddenCount);
        $this->assertSame('Arc 60', $section->items[0]->name, 'Newest first');
        $this->assertSame('Arc 30', $section->items[30]->name, 'The oldest one shown');
        $this->assertSame(2, $section->items[0]->pity);
        $this->assertFalse($section->items[0]->isUp);
        $this->assertTrue($section->items[1]->isUp);
        $this->assertSame('icons/arc_60.webp', $section->items[0]->iconPath);
        $this->assertSame(60, $section->upKnown, 'Every S, not only the 50 of recent_s_pulls');
        $this->assertSame(45, $section->upCount);
    }

    public function testShowAllListsMoreThanTheFiftySPullsOfTheSummary(): void
    {
        $pullRepo = $this->createStub(PerfectworldPullRepository::class);
        $pullRepo->method('findBannerRows')->willReturn($this->arcRows(60, self::everyFourthLost(60)));

        $section = $this->createBuilder($pullRepo)->build($this->createProfile(), self::ARC, showAll: true)->sections[0];

        $this->assertCount(60, $section->items, 'recent_s_pulls stops at 50: the card reads s_pulls');
        $this->assertSame(0, $section->hiddenCount);
        $this->assertSame('Arc 60', $section->items[0]->name);
        $this->assertSame('Arc 1', $section->items[59]->name);
        $this->assertSame(60, $section->upKnown);
        $this->assertSame(45, $section->upCount);
    }

    public function testAllBannersKeepOnlyTheBannersWithPullsEachWithItsOwnUnit(): void
    {
        $profile = $this->createProfile();
        $rowsByBanner = [
            self::LIMITED => $this->boardRows(20, 'Char'),
            self::STANDARD => [],
            self::ARC => $this->arcRows(2),
        ];

        $askedBanners = [];
        $pullRepo = $this->createMock(PerfectworldPullRepository::class);
        $pullRepo->expects($this->exactly(6))
            ->method('findBannerRows')
            ->willReturnCallback(static function (PerfectworldGameProfile $asked, string $bannerKey) use ($profile, $rowsByBanner, &$askedBanners): array {
                self::assertSame($profile, $asked);
                $askedBanners[] = $bannerKey;

                return $rowsByBanner[$bannerKey];
            });
        $builder = $this->createBuilder($pullRepo);

        $card = $builder->build($profile);

        $this->assertSame([self::LIMITED, self::STANDARD, self::ARC], $askedBanners);
        $this->assertSame('All banners', $card->title);
        $this->assertSame('Pulls', $card->pullLabel);
        $this->assertSame(
            ['Scarborough Fair (Limited Boards)', 'Arc Research Program'],
            array_map(static fn (PullCardSection $section): string => $section->name, $card->sections),
            'The standard board has no pulls',
        );
        $this->assertSame(['Rolls', 'Arcs'], array_map(static fn (PullCardSection $section): ?string => $section->pullLabel, $card->sections));
        $this->assertSame([40, 4], array_map(static fn (PullCardSection $section): int => $section->totalPulls, $card->sections));
        $this->assertCount(PerfectworldPullCardBuilder::PROFILE_LIMIT, $card->sections[0]->items);
        $this->assertSame(5, $card->sections[0]->hiddenCount);
        $this->assertSame('Char 20', $card->sections[0]->items[0]->name);
        $this->assertSame(['Arc 2', 'Arc 1'], self::itemNames($card->sections[1]));
        $this->assertSame(0, $card->sections[1]->hiddenCount);

        $all = $builder->build($profile, showAll: true);
        $this->assertCount(20, $all->sections[0]->items);
        $this->assertSame(0, $all->sections[0]->hiddenCount);
    }

    public function testUnknownBannerIsRefused(): void
    {
        $pullRepo = $this->createMock(PerfectworldPullRepository::class);
        $pullRepo->expects($this->never())->method('findBannerRows');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown banner "Lottery".');

        $this->createBuilder($pullRepo)->build($this->createProfile(), 'Lottery');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function gamesWithoutPullTracker(): iterable
    {
        yield 'game without pull tracker' => [2];
        yield 'unknown game' => [99];
    }

    #[DataProvider('gamesWithoutPullTracker')]
    public function testProfileOfAGameWithoutPullTrackerIsRefused(int $gameId): void
    {
        $pullRepo = $this->createMock(PerfectworldPullRepository::class);
        $pullRepo->expects($this->never())->method('findBannerRows');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This game profile does not support pull tracking.');

        $this->createBuilder($pullRepo)->build($this->createProfile($gameId));
    }

    public function testPlayerNameAndUidAppearOnlyWhenAsked(): void
    {
        $profile = $this->createProfile();
        $pullRepo = $this->createStub(PerfectworldPullRepository::class);
        $pullRepo->method('findBannerRows')->willReturn([]);
        $builder = $this->createBuilder($pullRepo);

        $hidden = $builder->build($profile);
        $this->assertNull($hidden->playerName);
        $this->assertNull($hidden->playerUid);
        $this->assertSame([], $hidden->sections, 'No banner has pulls');

        $shown = $builder->build($profile, showPlayer: true);
        $this->assertSame('Player_1', $shown->playerName);
        $this->assertSame('100000000001', $shown->playerUid);
    }

    public function testGeneratedAtComesFromTheClock(): void
    {
        $pullRepo = $this->createStub(PerfectworldPullRepository::class);
        $pullRepo->method('findBannerRows')->willReturn([]);

        $card = $this->createBuilder($pullRepo)->build($this->createProfile());

        $this->assertSame(self::CLOCK_NOW, $card->generatedAt->format('Y-m-d H:i:s'));
    }
}

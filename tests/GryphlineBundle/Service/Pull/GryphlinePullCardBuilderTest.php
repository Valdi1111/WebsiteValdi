<?php

namespace App\Tests\GryphlineBundle\Service\Pull;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Pull\Card\PullCardItem;
use App\GamesBundle\Model\Pull\Card\PullCardSection;
use App\GamesBundle\Service\Pull\Card\PullCardSectionFactory;
use App\GamesBundle\Service\Pull\PullGapPlanner;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Repository\GryphlinePullRepository;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\GryphlineBundle\Service\Pull\GryphlinePullCardBuilder;
use App\GryphlineBundle\Service\Pull\GryphlinePullStatsCalculator;
use App\GryphlineBundle\Service\SkportAuthService;
use App\GryphlineBundle\Service\SkportSignerService;
use App\GryphlineBundle\Service\SkportUtilsService;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Content of the shareable pulls card of a Gryphline profile, over the real stats calculator.
 */
class GryphlinePullCardBuilderTest extends TestCase
{
    private const string CLOCK_NOW = '2026-11-27 08:30:00';
    private const string CHARTERED = 'E_CharacterGachaPoolType_Special';
    private const string ARSENAL = 'E_WeaponGachaPoolType_Standard';

    private int $nextId = 1;

    private function createProfile(string $gameBiz = ArknightsEndfieldService::GAME_BIZ): GryphlineGameProfile
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        $account = (new GryphlineAccount())->setUser($user);
        $profile = (new GryphlineGameProfile())
            ->setAccount($account)
            ->setGameId(ArknightsEndfieldService::GAME_ID)
            ->setGameBiz($gameBiz)
            ->setGameUid('4000000001')
            ->setNickname('Endministrator');
        (new \ReflectionProperty(GryphlineGameProfile::class, 'id'))->setValue($profile, 10);

        return $profile;
    }

    private function createArknightsEndfieldService(): ArknightsEndfieldService
    {
        return new ArknightsEndfieldService(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(HttpClientInterface::class),
            $this->createStub(SkportSignerService::class),
            $this->createStub(SkportAuthService::class),
            $this->createStub(SkportUtilsService::class),
            $this->createStub(DenormalizerInterface::class),
            $this->createStub(ObjectMapperInterface::class),
            new NullLogger(),
        );
    }

    private function createBuilder(GryphlinePullRepository $pullRepo): GryphlinePullCardBuilder
    {
        $endfield = $this->createArknightsEndfieldService();
        // A Gryphline game without pull tracker
        $otherGame = $this->createStub(GameInterface::class);
        $locator = new ServiceLocator([
            ArknightsEndfieldService::GAME_BIZ => static fn () => $endfield,
            'other' => static fn () => $otherGame,
        ]);

        $iconUrlBuilder = $this->createStub(PullItemIconUrlBuilder::class);
        $iconUrlBuilder->method('buildRowPath')->willReturnCallback(
            static fn (string|object $gameService, array $row): string => 'icons/'.$row['item_id'].'.webp'
        );

        return new GryphlinePullCardBuilder(
            $locator,
            $pullRepo,
            new GryphlinePullStatsCalculator(new PullGapPlanner()),
            new PullCardSectionFactory($iconUrlBuilder),
            new MockClock(self::CLOCK_NOW.' UTC'),
        );
    }

    /**
     * A row as returned by GryphlinePullRepository::findProfileRows() / findBannerRows(), one minute after the previous one.
     *
     * @return array<string, mixed>
     */
    private function pullRow(string $poolType, int $rarity, string $name, ?bool $isUp = null, ?string $poolName = 'Winter Hunt'): array
    {
        $id = $this->nextId++;

        return [
            'id' => $id,
            'seqId' => (string) (100 + $id),
            'poolType' => $poolType,
            'poolCategory' => str_starts_with($poolType, 'E_Weapon') ? 'weapon' : 'char',
            'poolId' => 'pool_1',
            'poolName' => $poolName,
            'itemId' => strtolower(str_replace(' ', '_', $name)),
            'name' => $name,
            'rarity' => $rarity,
            'isUp' => $isUp,
            'pulledAt' => new \DateTimeImmutable('2026-06-01 10:00:00')->modify(sprintf('+%d minutes', $id)),
        ];
    }

    /**
     * $sCount 6-star pulls, oldest first, each one right after a 4-star (pity 2), named "<prefix> 1" (oldest) to "<prefix> N".
     *
     * @param array<int, bool> $isUp stored rate-up flag by S number (1 = oldest), unknown for the others
     *
     * @return list<array<string, mixed>>
     */
    private function bannerRows(string $poolType, int $sCount, string $prefix, array $isUp = [], ?string $poolName = 'Winter Hunt'): array
    {
        $rows = [];
        for ($i = 1; $i <= $sCount; ++$i) {
            $rows[] = $this->pullRow($poolType, 4, 'Supply Crate', poolName: $poolName);
            $rows[] = $this->pullRow($poolType, 6, $prefix.' '.$i, $isUp[$i] ?? null, $poolName);
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

        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->once())
            ->method('findBannerRows')
            ->with($profile, [self::CHARTERED])
            ->willReturn($this->bannerRows(self::CHARTERED, 40, 'Op', $isUp));
        $pullRepo->expects($this->never())->method('findProfileRows');

        $card = $this->createBuilder($pullRepo)->build($profile, self::CHARTERED);

        $this->assertSame('Arknights: Endfield', $card->gameName);
        $this->assertSame('Chartered Headhunting', $card->title, 'The title is the banner group name');
        $this->assertSame('6-Star', $card->sLabel, 'The long rarity label');
        $this->assertSame('Headhunts', $card->pullLabel);
        $this->assertCount(1, $card->sections);

        $section = $card->sections[0];
        $this->assertSame('Chartered Headhunting', $section->name);
        $this->assertSame(80, $section->totalPulls);
        $this->assertSame(40, $section->sCount);
        $this->assertSame(2.0, $section->avgSPity);
        $this->assertSame(0, $section->sPity);
        $this->assertSame(80, $section->sMaxPity);
        $this->assertNull($section->pullLabel);

        $this->assertCount(GryphlinePullCardBuilder::BANNER_LIMIT, $section->items);
        $this->assertSame(9, $section->hiddenCount);
        $this->assertSame('Op 40', $section->items[0]->name, 'Newest first');
        $this->assertSame('Op 10', $section->items[30]->name, 'The oldest one shown');
        $this->assertSame(2, $section->items[0]->pity);
        $this->assertNull($section->items[0]->isUp);
        $this->assertTrue($section->items[30]->isUp);
        $this->assertSame('icons/op_40.webp', $section->items[0]->iconPath);
        $this->assertSame(10, $section->upKnown, 'Hidden S count too');
        $this->assertSame(9, $section->upCount);
    }

    public function testOneBannerWithShowAllListsEverySPull(): void
    {
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findBannerRows')->willReturn($this->bannerRows(self::CHARTERED, 40, 'Op'));

        $section = $this->createBuilder($pullRepo)->build($this->createProfile(), self::CHARTERED, showAll: true)->sections[0];

        $this->assertCount(40, $section->items);
        $this->assertSame(0, $section->hiddenCount);
        $this->assertSame('Op 40', $section->items[0]->name);
        $this->assertSame('Op 1', $section->items[39]->name);
    }

    public function testAllBannersKeepOnlyTheBannersWithPullsAndShowFewerSPullsEach(): void
    {
        $profile = $this->createProfile();
        $rows = [
            ...$this->bannerRows(self::CHARTERED, 20, 'Op'),
            ...$this->bannerRows(self::ARSENAL, 2, 'Blade', poolName: 'Deep Cold Issue'),
            // A pool type missing from the configuration becomes a banner of its own, named after its pool
            ...$this->bannerRows('E_CharacterGachaPoolType_Collab', 1, 'Guest', poolName: 'Collab Hunt'),
        ];

        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->exactly(2))->method('findProfileRows')->with($profile)->willReturn($rows);
        $pullRepo->expects($this->never())->method('findBannerRows');
        $builder = $this->createBuilder($pullRepo);

        $card = $builder->build($profile);

        $this->assertSame('All banners', $card->title);
        $this->assertSame('Arknights: Endfield', $card->gameName);
        $this->assertSame(
            ['Chartered Headhunting', 'Arsenal Issue', 'Collab Hunt'],
            array_map(static fn (PullCardSection $section): string => $section->name, $card->sections),
            'The banners without pulls are left out',
        );
        $this->assertSame([40, 4, 2], array_map(static fn (PullCardSection $section): int => $section->totalPulls, $card->sections));
        $this->assertCount(GryphlinePullCardBuilder::PROFILE_LIMIT, $card->sections[0]->items);
        $this->assertSame(5, $card->sections[0]->hiddenCount);
        $this->assertSame('Op 20', $card->sections[0]->items[0]->name);
        $this->assertSame(['Blade 2', 'Blade 1'], self::itemNames($card->sections[1]));
        $this->assertSame(0, $card->sections[1]->hiddenCount);
        $this->assertSame(['Guest 1'], self::itemNames($card->sections[2]));

        $all = $builder->build($profile, showAll: true);
        $this->assertCount(20, $all->sections[0]->items);
        $this->assertSame(0, $all->sections[0]->hiddenCount);
    }

    public function testUnknownBannerIsRefused(): void
    {
        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->never())->method('findBannerRows');

        $this->expectException(\InvalidArgumentException::class);
        // A pool category, not a banner key
        $this->expectExceptionMessage('Unknown banner "char".');

        $this->createBuilder($pullRepo)->build($this->createProfile(), 'char');
    }

    public function testAnUnconfiguredPoolTypeIsABannerOfItsOwn(): void
    {
        $profile = $this->createProfile();
        $rows = [
            ...$this->bannerRows(self::CHARTERED, 2, 'Op'),
            // Listed by the overview as a banner of its own, so the share dialog offers it
            ...$this->bannerRows('E_CharacterGachaPoolType_Collab', 1, 'Guest', poolName: 'Collab Hunt'),
        ];

        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->once())->method('findProfileRows')->with($profile)->willReturn($rows);
        $pullRepo->expects($this->never())->method('findBannerRows');

        $card = $this->createBuilder($pullRepo)->build($profile, 'E_CharacterGachaPoolType_Collab');

        $this->assertSame('Collab Hunt', $card->title);
        $this->assertCount(1, $card->sections);
        $this->assertSame(['Guest 1'], self::itemNames($card->sections[0]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gamesWithoutPullTracker(): iterable
    {
        yield 'game without pull tracker' => ['other'];
        yield 'unknown game' => ['unknown'];
    }

    #[DataProvider('gamesWithoutPullTracker')]
    public function testProfileOfAGameWithoutPullTrackerIsRefused(string $gameBiz): void
    {
        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->never())->method('findProfileRows');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This game profile does not support pull tracking.');

        $this->createBuilder($pullRepo)->build($this->createProfile($gameBiz));
    }

    public function testPlayerNameAndUidAppearOnlyWhenAsked(): void
    {
        $profile = $this->createProfile();
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findProfileRows')->willReturn([]);
        $builder = $this->createBuilder($pullRepo);

        $hidden = $builder->build($profile);
        $this->assertNull($hidden->playerName);
        $this->assertNull($hidden->playerUid);
        $this->assertSame([], $hidden->sections, 'No banner has pulls');

        $shown = $builder->build($profile, showPlayer: true);
        $this->assertSame('Endministrator', $shown->playerName);
        $this->assertSame('4000000001', $shown->playerUid);
    }

    public function testGeneratedAtComesFromTheClock(): void
    {
        $pullRepo = $this->createStub(GryphlinePullRepository::class);
        $pullRepo->method('findProfileRows')->willReturn([]);

        $card = $this->createBuilder($pullRepo)->build($this->createProfile());

        $this->assertSame(self::CLOCK_NOW, $card->generatedAt->format('Y-m-d H:i:s'));
    }

    public function testProviderKeyIsTheOneOfTheGryphlineProfiles(): void
    {
        $this->assertSame('gryphline', GryphlinePullCardBuilder::getProviderKey());
    }

    public function testSupportsAProfileOfAGameWithPullTracker(): void
    {
        $this->assertTrue($this->createBuilder($this->createStub(GryphlinePullRepository::class))->supports($this->createProfile()));
    }

    #[DataProvider('gamesWithoutPullTracker')]
    public function testDoesNotSupportAProfileOfAGameWithoutPullTracker(string $gameBiz): void
    {
        $builder = $this->createBuilder($this->createStub(GryphlinePullRepository::class));

        $this->assertFalse($builder->supports($this->createProfile($gameBiz)));
        $this->assertSame([], $builder->getBanners($this->createProfile($gameBiz)));
    }

    /**
     * @return iterable<string, array{GameProfileInterface}>
     */
    public static function foreignProfiles(): iterable
    {
        yield 'fake profile' => [new FakeGameProfile(10, ArknightsEndfieldService::GAME_BIZ)];
        // The game biz of Arknights: Endfield, but another provider
        yield 'HoYoverse profile' => [new HoyoverseGameProfile()->setGameBiz(ArknightsEndfieldService::GAME_BIZ)->setGameId(ArknightsEndfieldService::GAME_ID)];
    }

    #[DataProvider('foreignProfiles')]
    public function testRefusesTheProfileOfAnotherProvider(GameProfileInterface $profile): void
    {
        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->never())->method('findProfileRows');
        $pullRepo->expects($this->never())->method('findBannerRows');
        $builder = $this->createBuilder($pullRepo);

        $this->assertFalse($builder->supports($profile));
        $this->assertSame([], $builder->getBanners($profile));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a Gryphline game profile.');

        $builder->buildCard($profile);
    }

    public function testBannersAreTheConfiguredGroupsInTheirOrder(): void
    {
        $banners = $this->createBuilder($this->createStub(GryphlinePullRepository::class))->getBanners($this->createProfile());

        $this->assertSame([
            ['key' => self::CHARTERED, 'name' => 'Chartered Headhunting'],
            ['key' => 'E_CharacterGachaPoolType_Rerun', 'name' => 'RE-Factor Headhunting'],
            ['key' => 'E_CharacterGachaPoolType_Joint', 'name' => 'Special Headhunting'],
            ['key' => 'E_CharacterGachaPoolType_Standard', 'name' => 'Basic Headhunting'],
            ['key' => self::ARSENAL, 'name' => 'Arsenal Issue'],
            ['key' => 'E_WeaponGachaPoolType_Rerun', 'name' => 'RE-Factor Issue'],
            ['key' => 'E_CharacterGachaPoolType_Beginner', 'name' => 'Beginner Headhunting'],
        ], $banners);
    }

    public function testBuildCardBuildsTheCardOfTheProfileWithEveryOption(): void
    {
        $profile = $this->createProfile();
        $rows = $this->bannerRows(self::CHARTERED, 40, 'Op');
        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->exactly(2))->method('findBannerRows')->with($profile, [self::CHARTERED])->willReturn($rows);
        $builder = $this->createBuilder($pullRepo);

        $card = $builder->buildCard($profile, self::CHARTERED, showAll: true, showPlayer: true);

        $this->assertEquals($builder->build($profile, self::CHARTERED, true, true), $card);
        $this->assertSame('Chartered Headhunting', $card->title);
        $this->assertCount(40, $card->sections[0]->items, 'Every S pull');
        $this->assertSame('Endministrator', $card->playerName);
        $this->assertSame('4000000001', $card->playerUid);
    }

    public function testBuildCardWithoutOptionsIsTheCardOfEveryBanner(): void
    {
        $profile = $this->createProfile();
        $pullRepo = $this->createMock(GryphlinePullRepository::class);
        $pullRepo->expects($this->once())->method('findProfileRows')->with($profile)->willReturn($this->bannerRows(self::CHARTERED, 20, 'Op'));
        $pullRepo->expects($this->never())->method('findBannerRows');

        $card = $this->createBuilder($pullRepo)->buildCard($profile);

        $this->assertSame('All banners', $card->title);
        $this->assertCount(GryphlinePullCardBuilder::PROFILE_LIMIT, $card->sections[0]->items);
        $this->assertNull($card->playerName);
        $this->assertNull($card->playerUid);
    }

    public function testBuildCardRefusesAProfileOfAGameWithoutPullTracker(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This game profile does not support pull tracking.');

        $this->createBuilder($this->createStub(GryphlinePullRepository::class))->buildCard($this->createProfile('other'));
    }
}

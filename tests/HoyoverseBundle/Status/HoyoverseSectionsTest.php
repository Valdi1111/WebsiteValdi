<?php

namespace App\Tests\HoyoverseBundle\Status;

use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\GameNotes\GameNotesStateMetric;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\HoyoverseBundle\Model\Notes\ZenlessZoneZeroVhsSale;
use App\HoyoverseBundle\Model\ParsedCookie;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Presenter\ExpeditionsPresenter;
use App\HoyoverseBundle\Presenter\RealmCurrencyPresenter;
use App\HoyoverseBundle\Presenter\ShopStatusPresenter;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\TearsOfThemisService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use App\HoyoverseBundle\Status\Section\ExpeditionsSection;
use App\HoyoverseBundle\Status\Section\RealmCurrencySection;
use App\HoyoverseBundle\Status\Section\ShopStatusSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * HoYoverse sections of the profile status: supported by the capability of the game service, read through it, and
 * summarized with the condition of the matching reminder.
 */
class HoyoverseSectionsTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private static function expeditionsSection(): ExpeditionsSection
    {
        $clock = new MockClock(self::now());

        return new ExpeditionsSection(new ExpeditionsPresenter(new GameProfileEmbedFactory($clock), new DurationFormatter()), new DurationFormatter(), $clock);
    }

    private static function realmCurrencySection(): RealmCurrencySection
    {
        $clock = new MockClock(self::now());

        return new RealmCurrencySection(new RealmCurrencyPresenter(new GameProfileEmbedFactory($clock), new DurationFormatter()), new DurationFormatter(), $clock);
    }

    private static function shopStatusSection(): ShopStatusSection
    {
        return new ShopStatusSection(new ShopStatusPresenter(new GameProfileEmbedFactory(new MockClock(self::now()))));
    }

    private static function profile(string $gameName = 'Genshin Impact', int $realmCurrencyThreshold = -1): HoyoverseGameProfile
    {
        return new HoyoverseGameProfile()
            ->setGameUid('100000001')
            ->setGameName($gameName)
            ->setNickname('Traveler')
            ->setLevel(60)
            ->setRealmCurrencyThreshold($realmCurrencyThreshold);
    }

    private static function accountData(HoyoverseGameProfile $profile): RuntimeAccountData
    {
        return new RuntimeAccountData()
            ->setParsedCookie(new ParsedCookie()->setLtokenV2('v2_test')->setLtuidV2('100000001')->setLtmidV2('mid_test'))
            ->setGameProfile($profile);
    }

    private static function context(object $gameService, ?RuntimeAccountData $accountData): GameProfileContext
    {
        $profile = $accountData?->getGameProfile() ?? self::profile();

        return new GameProfileContext(
            profile: $profile,
            ref: new GameProfileRef('hoyoverse', 42),
            game: new GameDescriptor('hoyoverse', 'hk4e_global', 'gi', 'Genshin Impact'),
            gameService: $gameService,
            accountData: $accountData,
        );
    }

    private static function line(string $label, string $value, string $icon, bool $attention = false): SummaryLine
    {
        return new SummaryLine($label, $value, $icon, $attention);
    }

    public function testKeysLabelsAndIcons(): void
    {
        $this->assertSame(
            [
                ['expeditions', 'Expeditions', '🧭', true],
                ['realm_currency', 'Realm Currency', '💰', true],
                ['shop_status', 'Shop', '📼', true],
            ],
            array_map(
                static fn (ProfileSectionInterface $section): array => [$section->getKey(), $section->getLabel(), $section->getIcon(), $section->usesNotes()],
                [self::expeditionsSection(), self::realmCurrencySection(), self::shopStatusSection()],
            ),
        );
    }

    /**
     * @return iterable<string, array{0: class-string, 1: list<string>}> game service => keys of the sections it supports
     */
    public static function supportedSections(): iterable
    {
        yield 'Genshin Impact' => [GenshinImpactService::class, ['expeditions', 'realm_currency']];
        yield 'Honkai: Star Rail' => [HonkaiStarRailService::class, ['expeditions']];
        yield 'Zenless Zone Zero' => [ZenlessZoneZeroService::class, ['shop_status']];
        yield 'Tears of Themis' => [TearsOfThemisService::class, []];
    }

    /**
     * @param class-string $serviceClass
     * @param list<string> $expected
     */
    #[DataProvider('supportedSections')]
    public function testSupportsFollowsTheCapabilitiesOfTheGameService(string $serviceClass, array $expected): void
    {
        $gameService = new $serviceClass(new NullLogger());
        $sections = [self::expeditionsSection(), self::realmCurrencySection(), self::shopStatusSection()];

        $supported = array_filter($sections, static fn (ProfileSectionInterface $section): bool => $section->supports(self::context($gameService, self::accountData(self::profile()))));
        $this->assertSame($expected, array_values(array_map(static fn (ProfileSectionInterface $section): string => $section->getKey(), $supported)));

        // Without account data, no section reads the game API
        foreach ($sections as $section) {
            $this->assertFalse($section->supports(self::context($gameService, null)), $section->getKey());
        }
    }

    public function testCollectReadsThroughTheGameService(): void
    {
        $accountData = self::accountData(self::profile());

        $expeditions = new GameNotesExpeditions()->addExpedition('avatar-1.png', 'finished', 0);
        $expeditionsService = $this->createMock(HasExpeditionsInterface::class);
        $expeditionsService->expects($this->once())->method('getExpeditionsData')->with($accountData)->willReturn($expeditions);
        $this->assertSame($expeditions, self::expeditionsSection()->collect(self::context($expeditionsService, $accountData)));

        $realm = new GameNotesRealm()->setCurrentCoin(300)->setMaxCoin(2400);
        $realmService = $this->createMock(HasRealmCurrencyInterface::class);
        $realmService->expects($this->once())->method('getRealmData')->with($accountData)->willReturn($realm);
        $this->assertSame($realm, self::realmCurrencySection()->collect(self::context($realmService, $accountData)));

        $shopStatus = new GameNotesStateMetric()->setCurrentValue(ZenlessZoneZeroVhsSale::DOING)->setTargetValue(ZenlessZoneZeroVhsSale::DONE);
        $shopService = $this->createMock(HasShopStatusInterface::class);
        $shopService->expects($this->once())->method('getShopStatusData')->with($accountData)->willReturn($shopStatus);
        $this->assertSame($shopStatus, self::shopStatusSection()->collect(self::context($shopService, $accountData)));
    }

    public function testPresentUsesTheSectionTitles(): void
    {
        $profile = self::profile('Zenless Zone Zero');
        $shopStatus = new GameNotesStateMetric()->setName('Shop Status')->setCurrentValue(ZenlessZoneZeroVhsSale::DONE)->setTargetValue(ZenlessZoneZeroVhsSale::DONE);

        $this->assertSame('Zenless Zone Zero - Expeditions', self::expeditionsSection()->present($profile, new GameNotesExpeditions())->getTitle());
        $this->assertSame('Zenless Zone Zero - Realm Currency', self::realmCurrencySection()->present($profile, new GameNotesRealm()->setCurrentCoin(1)->setMaxCoin(2))->getTitle());
        $this->assertSame('Zenless Zone Zero - Shop Status', self::shopStatusSection()->present($profile, $shopStatus)->getTitle());
    }

    /**
     * @return iterable<string, array{0: GameNotesExpeditions, 1: string, 2: bool}> expeditions => value, attention
     */
    public static function expeditions(): iterable
    {
        // Notes fetched 4 minutes before the clock of the section
        $fetchedAt = self::now()->modify('-240 seconds');

        yield 'still out' => [
            new GameNotesExpeditions()
                ->addExpedition('avatar-1.png', 'finished', 0, $fetchedAt)
                ->addExpedition('avatar-2.png', 'ongoing', 3600, $fetchedAt->modify('+3600 seconds'))
                ->addExpedition('avatar-3.png', 'ongoing', 7200, $fetchedAt->modify('+7200 seconds')),
            '1/3 back · all back in 1 hr, 56 min',
            false,
        ];
        yield 'all back' => [
            new GameNotesExpeditions()
                ->addExpedition('avatar-1.png', 'finished', 0)
                ->addExpedition('avatar-2.png', 'finished', 0),
            '2/2 back',
            true,
        ];
        yield 'return time unknown' => [
            new GameNotesExpeditions()
                ->addExpedition('avatar-1.png', 'ongoing', 3600)
                ->addExpedition('avatar-2.png', 'ongoing', null),
            '0/2 back',
            false,
        ];
        yield 'cached notes, every return passed' => [
            new GameNotesExpeditions()
                ->addExpedition('avatar-1.png', 'finished', 0, $fetchedAt)
                ->addExpedition('avatar-2.png', 'ongoing', 60, $fetchedAt->modify('+60 seconds')),
            '2/2 back',
            true,
        ];
        yield 'none dispatched' => [new GameNotesExpeditions(), 'None dispatched', false];
    }

    #[DataProvider('expeditions')]
    public function testExpeditionsSummary(GameNotesExpeditions $expeditions, string $value, bool $attention): void
    {
        $this->assertEquals(
            self::line('Expeditions', $value, '🧭', $attention),
            self::expeditionsSection()->summarize(self::profile(), $expeditions),
        );
    }

    /**
     * @return iterable<string, array{0: GameNotesRealm, 1: int, 2: string, 3: bool}> realm, threshold => value, attention
     */
    public static function realmCurrencies(): iterable
    {
        $fetchedAt = self::now()->modify('-240 seconds');

        yield 'below the threshold' => [
            new GameNotesRealm()->setCurrentCoin(1000)->setMaxCoin(2400)->setCoinRecoverTime(3 * 3600 + 1200 + 15),
            2000,
            '1000/2400 · full in 3 hr, 20 min',
            false,
        ];
        yield 'at the threshold' => [
            new GameNotesRealm()->setCurrentCoin(2000)->setMaxCoin(2400)->setCoinRecoverTime(3600)->setFullAt($fetchedAt->modify('+7200 seconds')),
            2000,
            '2000/2400 · full in 1 hr, 56 min',
            true,
        ];
        yield 'full without threshold' => [
            new GameNotesRealm()->setCurrentCoin(2400)->setMaxCoin(2400)->setCoinRecoverTime(0),
            -1,
            '2400/2400 · full',
            true,
        ];
        yield 'not full without threshold' => [
            new GameNotesRealm()->setCurrentCoin(2300)->setMaxCoin(2400),
            -1,
            '2300/2400',
            false,
        ];
        yield 'cached notes, full instant passed' => [
            new GameNotesRealm()->setCurrentCoin(2390)->setMaxCoin(2400)->setCoinRecoverTime(60)->setFullAt($fetchedAt->modify('+60 seconds')),
            -1,
            '2400/2400 · full',
            true,
        ];
        // isFull() would read a missing current and max as equal
        yield 'unknown' => [new GameNotesRealm(), -1, 'Unknown', false];
        yield 'unknown maximum' => [new GameNotesRealm()->setCurrentCoin(2000), 0, 'Unknown', false];
    }

    #[DataProvider('realmCurrencies')]
    public function testRealmCurrencySummary(GameNotesRealm $realm, int $threshold, string $value, bool $attention): void
    {
        $this->assertEquals(
            self::line('Realm Currency', $value, '💰', $attention),
            self::realmCurrencySection()->summarize(self::profile(realmCurrencyThreshold: $threshold), $realm),
        );
    }

    public function testRealmCurrencyOfAnotherProfileNeedsAttentionWhenFull(): void
    {
        $profile = $this->createStub(GameProfileInterface::class);
        $section = self::realmCurrencySection();

        $this->assertFalse($section->summarize($profile, new GameNotesRealm()->setCurrentCoin(2300)->setMaxCoin(2400))->attention);
        $this->assertTrue($section->summarize($profile, new GameNotesRealm()->setCurrentCoin(2400)->setMaxCoin(2400))->attention);
    }

    /**
     * @return iterable<string, array{0: ?ZenlessZoneZeroVhsSale, 1: string, 2: bool}> state => value, attention
     */
    public static function shopStates(): iterable
    {
        yield 'closed' => [ZenlessZoneZeroVhsSale::NO, 'Closed', false];
        yield 'open' => [ZenlessZoneZeroVhsSale::DOING, 'Open', false];
        yield 'finished' => [ZenlessZoneZeroVhsSale::DONE, 'Finished', true];
        yield 'unknown' => [null, 'Unknown', false];
    }

    #[DataProvider('shopStates')]
    public function testShopStatusSummary(?ZenlessZoneZeroVhsSale $state, string $value, bool $attention): void
    {
        $shopStatus = new GameNotesStateMetric()
            ->setName('Shop Status')
            ->setCurrentValue($state)
            ->setTargetValue(ZenlessZoneZeroVhsSale::DONE);

        $this->assertEquals(
            self::line('Shop', $value, '📼', $attention),
            self::shopStatusSection()->summarize(self::profile('Zenless Zone Zero'), $shopStatus),
        );
    }

    public function testShopStatusWithoutStateOrTargetIsUnknown(): void
    {
        // isDone() compares the missing state with the missing target as equal: no attention for it
        $summary = self::shopStatusSection()->summarize(self::profile('Zenless Zone Zero'), new GameNotesStateMetric());

        $this->assertEquals(self::line('Shop', 'Unknown', '📼'), $summary);
    }
}

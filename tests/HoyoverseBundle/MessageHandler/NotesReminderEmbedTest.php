<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Model\GameNotes\GameNotesStateMetric;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Presenter\WeekliesPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\DailiesReminderProfileMessage;
use App\HoyoverseBundle\Message\Profile\ExpeditionsCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\RealmCurrencyCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\ShopStatusCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\StaminaCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\AbstractProfileTaskMessageHandler;
use App\HoyoverseBundle\MessageHandler\Profile\DailiesReminderProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\ExpeditionsCheckProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\RealmCurrencyCheckProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\ShopStatusCheckProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\StaminaCheckProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\WeekliesReminderProfileHandler;
use App\HoyoverseBundle\Model\Notes\GameNotesExpeditions;
use App\HoyoverseBundle\Model\Notes\GameNotesRealm;
use App\HoyoverseBundle\Model\Notes\ZenlessZoneZeroVhsSale;
use App\HoyoverseBundle\Presenter\ExpeditionsPresenter;
use App\HoyoverseBundle\Presenter\RealmCurrencyPresenter;
use App\HoyoverseBundle\Presenter\ShopStatusPresenter;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * The whole embed sent by each notes reminder for a given state: header, wording, data fields and their order.
 */
class NotesReminderEmbedTest extends TestCase
{
    private const string NOW = '2026-03-17 15:00:00';

    private const array PLAYER_FIELDS = [
        ['Player', '(`100000001`) Traveler', '👤', true],
        ['Region', 'Global', '🌍', true],
        ['Rank', '60', '🏆', true],
    ];

    /** @var list<UniversalEmbed> */
    private array $sent = [];

    private function createProfile(string $gameName): HoyoverseGameProfile
    {
        $account = new HoyoverseAccount();
        $account->setUser(new User());
        $account->setCookie('ltoken_v2=test; ltuid_v2=123;');

        return new HoyoverseGameProfile()
            ->setAccount($account)
            ->setGameId(1)
            ->setGameUid('100000001')
            ->setGameName($gameName)
            ->setNickname('Traveler')
            ->setLevel(60)
            ->setNotificationPlatforms(['discord']);
    }

    /**
     * @template H of AbstractProfileTaskMessageHandler
     *
     * @param class-string<H> $class
     *
     * @return H
     */
    private function createHandler(string $class, HoyoverseGameProfile $profile, object $gameService): AbstractProfileTaskMessageHandler
    {
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($gameService);

        $notificationService = $this->createStub(UnifiedNotificationService::class);
        $notificationService->method('sendToUser')->willReturnCallback(function (User $user, UniversalEmbed $embed, array $platforms): void {
            $this->assertSame(['discord'], $platforms);
            $this->sent[] = $embed;
        });

        $handler = new $class($repo, $this->createStub(HoyolabCookieUtilsService::class), $locator, new NullLogger(), $notificationService);

        $embedFactory = new GameProfileEmbedFactory(new MockClock(self::NOW, 'UTC'));
        match (true) {
            $handler instanceof StaminaCheckProfileHandler => $handler->setStaminaPresenter(new StaminaPresenter($embedFactory, new DurationFormatter())),
            $handler instanceof DailiesReminderProfileHandler => $handler->setDailiesPresenter(new DailiesPresenter($embedFactory)),
            $handler instanceof WeekliesReminderProfileHandler => $handler->setWeekliesPresenter(new WeekliesPresenter($embedFactory)),
            $handler instanceof ExpeditionsCheckProfileHandler => $handler->setExpeditionsPresenter(new ExpeditionsPresenter($embedFactory, new DurationFormatter())),
            $handler instanceof RealmCurrencyCheckProfileHandler => $handler->setRealmCurrencyPresenter(new RealmCurrencyPresenter($embedFactory, new DurationFormatter())),
            $handler instanceof ShopStatusCheckProfileHandler => $handler->setShopStatusPresenter(new ShopStatusPresenter($embedFactory)),
        };

        return $handler;
    }

    /**
     * @return array{title: ?string, description: ?string, color: ?int, footer: array{0: ?string, 1: ?string}, fields: list<array{0: ?string, 1: ?string, 2: ?string, 3: bool}>}
     */
    private function sentEmbed(): array
    {
        $this->assertCount(1, $this->sent);
        $embed = $this->sent[0];
        $this->assertEquals(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), $embed->getTimestamp());

        return [
            'title' => $embed->getTitle(),
            'description' => $embed->getDescription(),
            'color' => $embed->getColor(),
            'footer' => [$embed->getFooterText(), $embed->getFooterIconUrl()],
            'fields' => array_map(
                static fn (EmbedField $field) => [$field->getName(), $field->getValue(), $field->getIcon(), $field->isInline()],
                $embed->getFields()
            ),
        ];
    }

    public function testStaminaWithinTheThreshold(): void
    {
        $profile = $this->createProfile('Honkai: Star Rail')->setStaminaCheck(true)->setStaminaThreshold(200);
        $service = $this->createStub(HonkaiStarRailService::class);
        $service->method('getStaminaData')->willReturn(new GameNotesStamina()->setCurrentStamina(240)->setMaxStamina(300)->setStaminaRecoverTime(5430));

        $this->createHandler(StaminaCheckProfileHandler::class, $profile, $service)(new StaminaCheckProfileMessage(1));

        $this->assertSame([
            'title' => 'Honkai: Star Rail - Stamina Reminder',
            'description' => 'Your stamina is within the set threshold!',
            'color' => 0x3498DB,
            'footer' => ['Honkai: Star Rail', 'https://hoyolab.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Stamina', '240/300', '🔋', true],
                ['Recovery Time', '1 hr, 30 min, 30 sec', '🕒', true],
            ],
        ], $this->sentEmbed());
    }

    public function testFullStaminaWithTheGameIconAndRegion(): void
    {
        $profile = $this->createProfile('Honkai: Star Rail')
            ->setStaminaCheck(true)
            ->setStaminaThreshold(-1)
            ->setIconUrl('https://example.test/hsr.png')
            ->setParsedRegion('Europe');
        $service = $this->createStub(HonkaiStarRailService::class);
        $service->method('getStaminaData')->willReturn(new GameNotesStamina()->setCurrentStamina(300)->setMaxStamina(300)->setStaminaRecoverTime(0));

        $this->createHandler(StaminaCheckProfileHandler::class, $profile, $service)(new StaminaCheckProfileMessage(1));

        $this->assertSame([
            'title' => 'Honkai: Star Rail - Stamina Reminder',
            'description' => 'Your stamina is full!',
            'color' => 0x3498DB,
            'footer' => ['Honkai: Star Rail', 'https://example.test/hsr.png'],
            'fields' => [
                self::PLAYER_FIELDS[0],
                ['Region', 'Europe', '🌍', true],
                self::PLAYER_FIELDS[2],
                ['Stamina', '300/300', '🔋', true],
                ['Recovery Time', '0 sec', '🕒', true],
            ],
        ], $this->sentEmbed());
    }

    public function testDailiesNotDone(): void
    {
        $dailies = new GameNotesDailies()
            ->addDaily(new GameNotesProgressMetric()->setName('Daily Training')->setCurrentValue(300)->setMaxValue(500))
            ->addDaily(new GameNotesProgressMetric()->setName('Synthesis')->setCurrentValue(1)->setMaxValue(1));
        $service = $this->createStub(HonkaiStarRailService::class);
        $service->method('getDailiesData')->willReturn($dailies);

        $this->createHandler(DailiesReminderProfileHandler::class, $this->createProfile('Honkai: Star Rail'), $service)(new DailiesReminderProfileMessage(1));

        $this->assertSame([
            'title' => 'Honkai: Star Rail - Dailies Reminder',
            'description' => "Don't Forget to Do Your Dailies!",
            'color' => 0x3498DB,
            'footer' => ['Honkai: Star Rail', 'https://hoyolab.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Daily Training', '300/500', '📅', true],
                ['Synthesis', '1/1', '📅', true],
            ],
        ], $this->sentEmbed());
    }

    public function testWeekliesNotDone(): void
    {
        $weeklies = new GameNotesWeeklies()
            ->addWeekly(new GameNotesProgressMetric()->setName('Echo of War')->setCurrentValue(1)->setMaxValue(3));
        $service = $this->createStub(HonkaiStarRailService::class);
        $service->method('getWeekliesData')->willReturn($weeklies);

        $this->createHandler(WeekliesReminderProfileHandler::class, $this->createProfile('Honkai: Star Rail'), $service)(new WeekliesReminderProfileMessage(1));

        $this->assertSame([
            'title' => 'Honkai: Star Rail - Weeklies Reminder',
            'description' => "Don't Forget to Do Your Weeklies!",
            'color' => 0x3498DB,
            'footer' => ['Honkai: Star Rail', 'https://hoyolab.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Echo of War', '1/3', '📊', true],
            ],
        ], $this->sentEmbed());
    }

    public function testExpeditionsAllDone(): void
    {
        $expeditions = new GameNotesExpeditions()
            ->addExpedition('avatar-1.png', 'finished', 0)
            ->addExpedition('avatar-2.png', 'finished', 0);
        $service = $this->createStub(GenshinImpactService::class);
        $service->method('getExpeditionsData')->willReturn($expeditions);

        $this->createHandler(ExpeditionsCheckProfileHandler::class, $this->createProfile('Genshin Impact'), $service)(new ExpeditionsCheckProfileMessage(1));

        $this->assertSame([
            'title' => 'Genshin Impact - Expeditions Reminder',
            'description' => 'All expeditions are completed!',
            'color' => 0x3498DB,
            'footer' => ['Genshin Impact', 'https://hoyolab.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Expeditions', '2/2 completed', '🧭', true],
            ],
        ], $this->sentEmbed());
    }

    public function testRealmCurrencyWithinTheThreshold(): void
    {
        $profile = $this->createProfile('Genshin Impact')->setRealmCurrencyCheck(true)->setRealmCurrencyThreshold(1500);
        $service = $this->createStub(GenshinImpactService::class);
        $service->method('getRealmData')->willReturn(new GameNotesRealm()->setCurrentCoin(2000)->setMaxCoin(2400)->setCoinRecoverTime(7260));

        $this->createHandler(RealmCurrencyCheckProfileHandler::class, $profile, $service)(new RealmCurrencyCheckProfileMessage(1));

        $this->assertSame([
            'title' => 'Genshin Impact - Realm Currency Reminder',
            'description' => 'Your realm currency is within the set threshold!',
            'color' => 0x3498DB,
            'footer' => ['Genshin Impact', 'https://hoyolab.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Realm Currency', '2000/2400', '💰', true],
                ['Recovery Time', '2 hr, 1 min, 0 sec', '🕒', true],
            ],
        ], $this->sentEmbed());
    }

    public function testShopStatusDone(): void
    {
        $shopStatus = new GameNotesStateMetric()
            ->setName('Shop Status')
            ->setCurrentValue(ZenlessZoneZeroVhsSale::DONE)
            ->setTargetValue(ZenlessZoneZeroVhsSale::DONE);
        $service = $this->createStub(ZenlessZoneZeroService::class);
        $service->method('getShopStatusData')->willReturn($shopStatus);

        $this->createHandler(ShopStatusCheckProfileHandler::class, $this->createProfile('Zenless Zone Zero'), $service)(new ShopStatusCheckProfileMessage(1));

        $this->assertSame([
            'title' => 'Zenless Zone Zero - Shop Status Reminder',
            'description' => 'Your shop has finished selling videos!',
            'color' => 0x3498DB,
            'footer' => ['Zenless Zone Zero', 'https://hoyolab.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Shop Status', 'Finished', '📼', true],
            ],
        ], $this->sentEmbed());
    }
}

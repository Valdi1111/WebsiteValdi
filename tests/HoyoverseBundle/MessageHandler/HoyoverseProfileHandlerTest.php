<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\EmbedField;
use App\GamesBundle\Message\ProfileTaskMessageInterface;
use App\GamesBundle\Model\Game\AwardData;
use App\GamesBundle\Model\Game\CheckInResult;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Presenter\WeekliesPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\DailiesReminderProfileMessage;
use App\HoyoverseBundle\Message\Profile\HoyolabCheckInProfileMessage;
use App\HoyoverseBundle\Message\Profile\HoyolabMissedCheckInProfileMessage;
use App\HoyoverseBundle\Message\Profile\StaminaCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\AbstractProfileTaskMessageHandler;
use App\HoyoverseBundle\MessageHandler\Profile\DailiesReminderProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\HoyolabCheckInProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\HoyolabMissedCheckInProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\StaminaCheckProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\WeekliesReminderProfileHandler;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Exception\SignInRiskException;
use App\Tests\HoyoverseBundle\CookieUtilsStubTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class HoyoverseProfileHandlerTest extends TestCase
{
    use CookieUtilsStubTrait;

    private static function embedFactory(): GameProfileEmbedFactory
    {
        return new GameProfileEmbedFactory(new MockClock());
    }

    private function createProfileWithUser(int $id = 1, int $gameId = 1): HoyoverseGameProfile
    {
        $user = new User();
        $account = new HoyoverseAccount();
        $account->setUser($user);
        $account->setCookie('ltoken_v2=test; ltuid_v2=123;');

        return (new HoyoverseGameProfile())
            ->setAccount($account)
            ->setGameId($gameId)
            ->setGameBiz('hkrpg_global')
            ->setGameUid('100000001')
            ->setGameName('Honkai: Star Rail')
            ->setNickname('Trailblazer')
            ->setLevel(70)
            ->setNotificationPlatforms(['telegram']);
    }

    public function testStaminaCheckProfileHandlerNotifiesWhenAboveThreshold(): void
    {
        $profile = $this->createProfileWithUser();
        $profile->setStaminaCheck(true)->setStaminaThreshold(200);

        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $staminaData = (new GameNotesStamina())
            ->setCurrentStamina(240)
            ->setMaxStamina(300)
            ->setStaminaRecoverTime(3600);

        $hsrService = $this->createStub(HonkaiStarRailService::class);
        $hsrService->method('getStaminaData')->willReturn($staminaData);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($hsrService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendToUser')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (UniversalEmbed $embed) {
                    $this->assertStringContainsString('Stamina Reminder', $embed->getTitle());
                    return true;
                }),
                ['telegram']
            );

        $cookieUtils = $this->parsingCookieUtils();

        $handler = new StaminaCheckProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler->setStaminaPresenter(new StaminaPresenter(self::embedFactory(), new DurationFormatter()));
        $handler(new StaminaCheckProfileMessage(1));
    }

    public function testStaminaCheckProfileHandlerSkipsWhenBelowThreshold(): void
    {
        $profile = $this->createProfileWithUser();
        $profile->setStaminaCheck(true)->setStaminaThreshold(250);

        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $staminaData = (new GameNotesStamina())
            ->setCurrentStamina(150)
            ->setMaxStamina(300);

        $hsrService = $this->createStub(HonkaiStarRailService::class);
        $hsrService->method('getStaminaData')->willReturn($staminaData);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($hsrService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');

        $cookieUtils = $this->parsingCookieUtils();

        $handler = new StaminaCheckProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler->setStaminaPresenter(new StaminaPresenter(self::embedFactory(), new DurationFormatter()));
        $handler(new StaminaCheckProfileMessage(1));
    }

    public function testStaminaCheckProfileHandlerEarlyReturnsWhenGameDoesNotImplementHasStamina(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $gameService = $this->createStub(GameInterface::class);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($gameService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');

        $cookieUtils = $this->parsingCookieUtils();

        $handler = new StaminaCheckProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler->setStaminaPresenter(new StaminaPresenter(self::embedFactory(), new DurationFormatter()));
        $handler(new StaminaCheckProfileMessage(1));
    }

    public function testDailiesReminderProfileHandlerNotifiesWhenIncomplete(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $dailies = new GameNotesDailies();
        $dailies->addDaily(
            (new GameNotesProgressMetric())
                ->setName('Daily Training')
                ->setCurrentValue(300)
                ->setMaxValue(500)
        );

        $hsrService = $this->createStub(HonkaiStarRailService::class);
        $hsrService->method('getDailiesData')->willReturn($dailies);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($hsrService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendToUser')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (UniversalEmbed $embed) {
                    $this->assertStringContainsString('Dailies Reminder', $embed->getTitle());
                    return true;
                }),
                ['telegram']
            );

        $cookieUtils = $this->parsingCookieUtils();

        $handler = new DailiesReminderProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler->setDailiesPresenter(new DailiesPresenter(self::embedFactory()));
        $handler(new DailiesReminderProfileMessage(1));
    }

    public function testDailiesReminderProfileHandlerSkipsWhenAllDone(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $dailies = new GameNotesDailies();
        $dailies->addDaily(
            (new GameNotesProgressMetric())
                ->setName('Daily Training')
                ->setCurrentValue(500)
                ->setMaxValue(500)
        );

        $hsrService = $this->createStub(HonkaiStarRailService::class);
        $hsrService->method('getDailiesData')->willReturn($dailies);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($hsrService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');

        $cookieUtils = $this->parsingCookieUtils();

        $handler = new DailiesReminderProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler->setDailiesPresenter(new DailiesPresenter(self::embedFactory()));
        $handler(new DailiesReminderProfileMessage(1));
    }

    public function testWeekliesReminderProfileHandlerNotifiesWhenIncomplete(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $weeklies = new GameNotesWeeklies();
        $weeklies->addWeekly(
            (new GameNotesProgressMetric())
                ->setName('Simulated Universe')
                ->setCurrentValue(1)
                ->setMaxValue(3)
        );

        $hsrService = $this->createStub(HonkaiStarRailService::class);
        $hsrService->method('getWeekliesData')->willReturn($weeklies);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($hsrService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendToUser')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (UniversalEmbed $embed) {
                    $this->assertStringContainsString('Weeklies Reminder', $embed->getTitle());
                    return true;
                }),
                ['telegram']
            );

        $cookieUtils = $this->parsingCookieUtils();

        $handler = new WeekliesReminderProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler->setWeekliesPresenter(new WeekliesPresenter(self::embedFactory()));
        $handler(new WeekliesReminderProfileMessage(1));
    }

    public function testHoyolabCheckInProfileHandlerSendsNotification(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $award = new AwardData();
        $award->setName('Stellar Jade x60')->setCount(60);

        $checkInResult = (new CheckInResult())
            ->setStatus('success')
            ->setResult('Check-in successful!')
            ->setTotalSignDay(20)
            ->setAwardData($award);

        $hsrService = $this->createStub(HonkaiStarRailService::class);
        $hsrService->method('checkIn')->willReturn($checkInResult);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($hsrService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendToUser')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (UniversalEmbed $embed) {
                    $this->assertStringContainsString('Daily Check-In', $embed->getTitle());
                    $this->assertStringContainsString('Check-in successful!', $embed->getDescription());
                    return true;
                }),
                ['telegram']
            );

        $cookieUtils = $this->parsingCookieUtils();

        $handler = new HoyolabCheckInProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler->setEmbedFactory(self::embedFactory());
        $handler(new HoyolabCheckInProfileMessage(1));
    }

    /**
     * @return iterable<string, array{class-string<AbstractProfileTaskMessageHandler<ProfileTaskMessageInterface>>, class-string<ProfileTaskMessageInterface>}>
     */
    public static function checkInHandlers(): iterable
    {
        yield 'auto check-in' => [HoyolabCheckInProfileHandler::class, HoyolabCheckInProfileMessage::class];
        yield 'missed check-in' => [HoyolabMissedCheckInProfileHandler::class, HoyolabMissedCheckInProfileMessage::class];
    }

    /**
     * @param class-string<AbstractProfileTaskMessageHandler<ProfileTaskMessageInterface>> $handlerClass
     * @param class-string<ProfileTaskMessageInterface>                                     $messageClass
     */
    #[DataProvider('checkInHandlers')]
    public function testHoyolabCheckInHandlersNotifyACaptchaAsNotClaimed(string $handlerClass, string $messageClass): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $hsrService = $this->createMock(HonkaiStarRailService::class);
        $hsrService->expects($this->once())
            ->method('checkIn')
            ->willThrowException(new SignInRiskException(5001));

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($hsrService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendToUser')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (UniversalEmbed $embed) {
                    $this->assertSame('Honkai: Star Rail - Daily Check-In Failed', $embed->getTitle());
                    $this->assertStringContainsString('NOT claimed', $embed->getDescription());
                    $this->assertStringContainsString('check in by hand', $embed->getDescription());
                    $this->assertSame(0xED4245, $embed->getColor());
                    // Only the profile header of the embed factory: no reward, no sign-in total
                    $this->assertSame(
                        ['Player', 'Region', 'Rank'],
                        array_map(static fn (EmbedField $field): ?string => $field->getName(), $embed->getFields())
                    );
                    return true;
                }),
                ['telegram']
            );

        $handler = new $handlerClass($repo, $this->parsingCookieUtils(), $locator, new NullLogger(), $notificationService);
        $handler->setEmbedFactory(self::embedFactory());
        // Returns normally: a thrown exception would make Messenger retry, and call sign again
        $handler(new $messageClass(1));
    }

    /**
     * @return array<string, string|null> field name => value
     */
    private static function fieldValues(UniversalEmbed $embed): array
    {
        $values = [];
        foreach ($embed->getFields() as $field) {
            $values[$field->getName()] = $field->getValue();
        }

        return $values;
    }

    /**
     * @param callable(UniversalEmbed): void $assertEmbed
     */
    private function runCheckInHandler(string $handlerClass, CheckInResult $checkInResult, int $expectedSends, ?callable $assertEmbed = null): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $hsrService = $this->createStub(HonkaiStarRailService::class);
        $hsrService->method('checkIn')->willReturn($checkInResult);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($hsrService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->exactly($expectedSends))
            ->method('sendToUser')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (UniversalEmbed $embed) use ($assertEmbed) {
                    if ($assertEmbed !== null) {
                        $assertEmbed($embed);
                    }
                    return true;
                }),
                ['telegram']
            );

        $handler = new $handlerClass($repo, $this->parsingCookieUtils(), $locator, new NullLogger(), $notificationService);
        $handler->setEmbedFactory(self::embedFactory());
        $message = $handlerClass === HoyolabMissedCheckInProfileHandler::class
            ? new HoyolabMissedCheckInProfileMessage(1)
            : new HoyolabCheckInProfileMessage(1);
        $handler($message);
    }

    public function testHoyolabCheckInProfileHandlerShowsTheAward(): void
    {
        $checkInResult = (new CheckInResult())
            ->setResult('Check-in successful!')
            ->setTotalSignDay(20)
            ->setAwardData((new AwardData())->setName('Stellar Jade')->setCount(60));

        $this->runCheckInHandler(HoyolabCheckInProfileHandler::class, $checkInResult, 1, function (UniversalEmbed $embed) {
            $fields = self::fieldValues($embed);
            $this->assertSame('Stellar Jade', $fields["Today's Reward"]);
            $this->assertSame('20', $fields['Total Sign-ins']);
        });
    }

    public function testHoyolabCheckInProfileHandlerNotifiesWithoutAward(): void
    {
        $checkInResult = (new CheckInResult())
            ->setAlreadySignedIn(true)
            ->setResult('Already checked in today')
            ->setTotalSignDay(0);

        $this->runCheckInHandler(HoyolabCheckInProfileHandler::class, $checkInResult, 1, function (UniversalEmbed $embed) {
            $this->assertStringContainsString('Already checked in today', $embed->getDescription());
            $fields = self::fieldValues($embed);
            $this->assertSame('Item', $fields["Today's Reward"]);
            $this->assertSame('0', $fields['Total Sign-ins']);
        });
    }

    public function testHoyolabMissedCheckInProfileHandlerNotifiesWithoutAward(): void
    {
        $checkInResult = (new CheckInResult())
            ->setResult('Check-in successful!')
            ->setTotalSignDay(31);

        $this->runCheckInHandler(HoyolabMissedCheckInProfileHandler::class, $checkInResult, 1, function (UniversalEmbed $embed) {
            $this->assertStringContainsString('Check-in successful!', $embed->getDescription());
            $fields = self::fieldValues($embed);
            $this->assertSame('Item', $fields["Today's Reward"]);
            $this->assertSame('31', $fields['Total Sign-ins']);
        });
    }

    public function testHoyolabMissedCheckInProfileHandlerSkipsWhenAlreadySignedIn(): void
    {
        $checkInResult = (new CheckInResult())
            ->setAlreadySignedIn(true)
            ->setResult('Already checked in today')
            ->setTotalSignDay(0);

        $this->runCheckInHandler(HoyolabMissedCheckInProfileHandler::class, $checkInResult, 0);
    }
}

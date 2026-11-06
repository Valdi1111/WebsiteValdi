<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Game\AwardData;
use App\CoreBundle\Model\Game\CheckInResult;
use App\CoreBundle\Model\GameNotes\GameNotesDailies;
use App\CoreBundle\Model\GameNotes\GameNotesProgressMetric;
use App\CoreBundle\Model\GameNotes\GameNotesStamina;
use App\CoreBundle\Model\GameNotes\GameNotesWeeklies;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\DailiesReminderProfileMessage;
use App\HoyoverseBundle\Message\Profile\HoyolabCheckInProfileMessage;
use App\HoyoverseBundle\Message\Profile\StaminaCheckProfileMessage;
use App\HoyoverseBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\DailiesReminderProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\HoyolabCheckInProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\StaminaCheckProfileHandler;
use App\HoyoverseBundle\MessageHandler\Profile\WeekliesReminderProfileHandler;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class HoyoverseProfileHandlerTest extends TestCase
{
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

        $hoyolabUtils = $this->createStub(HoyolabUtilsService::class);
        $hoyolabUtils->method('formatTime')->willReturn('1h 0m');

        $hsrService = $this->createStub(HonkaiStarRailService::class);
        $hsrService->method('getStaminaData')->willReturn($staminaData);
        $hsrService->method('getHoyolabUtils')->willReturn($hoyolabUtils);

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

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);

        $handler = new StaminaCheckProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
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

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);

        $handler = new StaminaCheckProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
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

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);

        $handler = new StaminaCheckProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
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

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);

        $handler = new DailiesReminderProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
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

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);

        $handler = new DailiesReminderProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
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

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);

        $handler = new WeekliesReminderProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
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

        $cookieUtils = $this->createStub(HoyolabCookieUtilsService::class);

        $handler = new HoyolabCheckInProfileHandler($repo, $cookieUtils, $locator, new NullLogger(), $notificationService);
        $handler(new HoyolabCheckInProfileMessage(1));
    }
}

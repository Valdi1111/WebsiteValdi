<?php

namespace App\Tests\GryphlineBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\GameNotes\GameNotesDailies;
use App\CoreBundle\Model\GameNotes\GameNotesProgressMetric;
use App\CoreBundle\Model\GameNotes\GameNotesStamina;
use App\CoreBundle\Model\GameNotes\GameNotesWeeklies;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Message\Profile\DailiesReminderProfileMessage;
use App\GryphlineBundle\Message\Profile\SkportCheckInProfileMessage;
use App\GryphlineBundle\Message\Profile\SkportMissedCheckInProfileMessage;
use App\GryphlineBundle\MessageHandler\Profile\SkportMissedCheckInProfileHandler;
use App\GryphlineBundle\Message\Profile\StaminaCheckProfileMessage;
use App\GryphlineBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\GryphlineBundle\MessageHandler\Profile\DailiesReminderProfileHandler;
use App\GryphlineBundle\MessageHandler\Profile\SkportCheckInProfileHandler;
use App\GryphlineBundle\MessageHandler\Profile\StaminaCheckProfileHandler;
use App\GryphlineBundle\MessageHandler\Profile\WeekliesReminderProfileHandler;
use App\CoreBundle\Model\Game\AwardData;
use App\CoreBundle\Model\Game\CheckInResult;
use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Service\EndfieldService;
use App\GryphlineBundle\Service\SkportUtilsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class GryphlineProfileHandlerTest extends TestCase
{
    private function createProfileWithUser(int $id = 1, string $biz = 'endfield'): GryphlineGameProfile
    {
        $user = new User();
        $account = new GryphlineAccount();
        $account->setUser($user);

        return (new GryphlineGameProfile())
            ->setAccount($account)
            ->setGameBiz($biz)
            ->setGameUid('123456789')
            ->setGameName('Arknights: Endfield')
            ->setNickname('Doctor')
            ->setLevel(60)
            ->setNotificationPlatforms(['telegram']);
    }

    public function testStaminaCheckProfileHandlerNotifiesWhenAboveThreshold(): void
    {
        $profile = $this->createProfileWithUser();
        $profile->setStaminaCheck(true)->setStaminaThreshold(200);

        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $staminaData = (new GameNotesStamina())
            ->setCurrentStamina(250)
            ->setMaxStamina(360)
            ->setStaminaRecoverTime(3600);

        $skportUtils = $this->createStub(SkportUtilsService::class);
        $skportUtils->method('formatTime')->willReturn('1h 0m');

        $endfieldService = $this->createStub(EndfieldService::class);
        $endfieldService->method('getStaminaData')->willReturn($staminaData);
        $endfieldService->method('getSkportUtils')->willReturn($skportUtils);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($endfieldService);

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

        $handler = new StaminaCheckProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new StaminaCheckProfileMessage(1));
    }

    public function testStaminaCheckProfileHandlerSkipsWhenBelowThreshold(): void
    {
        $profile = $this->createProfileWithUser();
        $profile->setStaminaCheck(true)->setStaminaThreshold(300);

        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $staminaData = (new GameNotesStamina())
            ->setCurrentStamina(150)
            ->setMaxStamina(360);

        $endfieldService = $this->createStub(EndfieldService::class);
        $endfieldService->method('getStaminaData')->willReturn($staminaData);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($endfieldService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');

        $handler = new StaminaCheckProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new StaminaCheckProfileMessage(1));
    }

    public function testStaminaCheckProfileHandlerEarlyReturnsWhenGameDoesNotImplementHasStamina(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $gameService = $this->createStub(GameInterface::class); // Not HasStaminaInterface

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($gameService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');

        $handler = new StaminaCheckProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new StaminaCheckProfileMessage(1));
    }

    public function testDailiesReminderProfileHandlerNotifiesWhenIncomplete(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $dailies = new GameNotesDailies();
        $dailies->addDaily(
            (new GameNotesProgressMetric())
                ->setName('Daily Activation')
                ->setCurrentValue(60)
                ->setMaxValue(100)
        );

        $endfieldService = $this->createStub(EndfieldService::class);
        $endfieldService->method('getDailiesData')->willReturn($dailies);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($endfieldService);

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

        $handler = new DailiesReminderProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new DailiesReminderProfileMessage(1));
    }

    public function testDailiesReminderProfileHandlerSkipsWhenAllDone(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $dailies = new GameNotesDailies();
        $dailies->addDaily(
            (new GameNotesProgressMetric())
                ->setName('Daily Activation')
                ->setCurrentValue(100)
                ->setMaxValue(100)
        );

        $endfieldService = $this->createStub(EndfieldService::class);
        $endfieldService->method('getDailiesData')->willReturn($dailies);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($endfieldService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method('sendToUser');

        $handler = new DailiesReminderProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new DailiesReminderProfileMessage(1));
    }

    public function testWeekliesReminderProfileHandlerNotifiesWhenIncomplete(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $weeklies = new GameNotesWeeklies();
        $weeklies->addWeekly(
            (new GameNotesProgressMetric())
                ->setName('Weekly Mission')
                ->setCurrentValue(7)
                ->setMaxValue(10)
        );

        $endfieldService = $this->createStub(EndfieldService::class);
        $endfieldService->method('getWeekliesData')->willReturn($weeklies);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($endfieldService);

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

        $handler = new WeekliesReminderProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new WeekliesReminderProfileMessage(1));
    }

    public function testSkportCheckInProfileHandlerSendsNotification(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $award = new AwardData();
        $award->setName('Oroberyl x100')->setCount(100);

        $checkInResult = (new CheckInResult())
            ->setStatus("success")
            ->setResult('Check-in completed successfully!')
            ->setTotalSignDay(15)
            ->setAwardData($award);

        $endfieldService = $this->createStub(EndfieldService::class);
        $endfieldService->method('checkIn')->willReturn($checkInResult);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($endfieldService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->once())
            ->method('sendToUser')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (UniversalEmbed $embed) {
                    $this->assertStringContainsString('Daily Check-In', $embed->getTitle());
                    $this->assertStringContainsString('Check-in completed successfully!', $embed->getDescription());
                    return true;
                }),
                ['telegram']
            );

        $handler = new SkportCheckInProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new SkportCheckInProfileMessage(1));
    }

    public function testSkportMissedCheckInProfileHandlerSendsNotificationWhenNotAlreadySignedIn(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method("find")->willReturn($profile);

        $award = new AwardData();
        $award->setName("Oroberyl x100")->setCount(100);

        $checkInResult = (new CheckInResult())
            ->setStatus("success")
            ->setAlreadySignedIn(false)
            ->setResult("Missed check-in recovered successfully!")
            ->setTotalSignDay(16)
            ->setAwardData($award);

        $endfieldService = $this->createStub(EndfieldService::class);
        $endfieldService->method("checkIn")->willReturn($checkInResult);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method("has")->willReturn(true);
        $locator->method("get")->willReturn($endfieldService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->once())
            ->method("sendToUser")
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (UniversalEmbed $embed) {
                    $this->assertStringContainsString("Daily Check-In", $embed->getTitle());
                    $this->assertStringContainsString("Missed check-in recovered successfully!", $embed->getDescription());
                    return true;
                }),
                ["telegram"]
            );

        $handler = new SkportMissedCheckInProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new SkportMissedCheckInProfileMessage(1));
    }

    public function testSkportMissedCheckInProfileHandlerSkipsNotificationWhenAlreadySignedIn(): void
    {
        $profile = $this->createProfileWithUser();
        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method("find")->willReturn($profile);

        $checkInResult = (new CheckInResult())
            ->setStatus("success")
            ->setAlreadySignedIn(true)
            ->setResult("Already signed in today.");

        $endfieldService = $this->createStub(EndfieldService::class);
        $endfieldService->method("checkIn")->willReturn($checkInResult);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method("has")->willReturn(true);
        $locator->method("get")->willReturn($endfieldService);

        $notificationService = $this->createMock(UnifiedNotificationService::class);
        $notificationService->expects($this->never())->method("sendToUser");

        $handler = new SkportMissedCheckInProfileHandler($repo, $locator, new NullLogger(), $notificationService);
        $handler(new SkportMissedCheckInProfileMessage(1));
    }
}

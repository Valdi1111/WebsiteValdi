<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Model\Endgame\GameEndgames;
use App\GamesBundle\Model\Endgame\GameEndgameStage;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Message\Profile\EndgamesReminderProfileMessage;
use App\HoyoverseBundle\MessageHandler\Profile\EndgamesReminderProfileHandler;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Repository\HoyoverseGameProfileRepository;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class EndgamesReminderProfileHandlerTest extends TestCase
{
    /** Shiyu Defense end used below: 2026-03-20 03:59:59 server time (UTC+01:00) */
    private const string END = '2026-03-20 03:59:59';

    /** @var list<UniversalEmbed> */
    private array $sent = [];

    private function createProfile(bool $fullClear): HoyoverseGameProfile
    {
        $account = new HoyoverseAccount();
        $account->setUser(new User());
        $account->setCookie('ltoken_v2=test; ltuid_v2=123;');

        return (new HoyoverseGameProfile())
            ->setAccount($account)
            ->setGameId(ZenlessZoneZeroService::GAME_ID)
            ->setGameBiz('nap_global')
            ->setGameUid('1300000001')
            ->setGameName('Zenless Zone Zero')
            ->setNickname('Proxy')
            ->setLevel(60)
            ->setEndgamesCheck(true)
            ->setEndgamesFullClear($fullClear)
            ->setNotificationPlatforms(['telegram']);
    }

    private function createHandler(
        object $gameService,
        bool $fullClear = false,
        string $now = '2026-03-17 15:00:00',
        ?LoggerInterface $logger = null,
    ): EndgamesReminderProfileHandler {
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($this->createProfile($fullClear));

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($gameService);

        $notificationService = $this->createStub(UnifiedNotificationService::class);
        $notificationService->method('sendToUser')->willReturnCallback(function (User $user, UniversalEmbed $embed, array $platforms): void {
            $this->assertSame(['telegram'], $platforms);
            $this->sent[] = $embed;
        });

        $handler = new EndgamesReminderProfileHandler(
            $repo,
            $this->createStub(HoyolabCookieUtilsService::class),
            $locator,
            $logger ?? new NullLogger(),
            $notificationService
        );
        // The task runs at 15:00 of the regional timezone
        $handler->setClock(new MockClock($now, 'Europe/Paris'));

        return $handler;
    }

    private function zenless(GameEndgame ...$endgames): ZenlessZoneZeroService
    {
        $service = $this->createStub(ZenlessZoneZeroService::class);
        $service->method('getEndgamesData')->willReturn(new GameEndgames($endgames));

        return $service;
    }

    private static function end(string $wallClock = self::END): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wallClock, new \DateTimeZone('+01:00'));
    }

    private static function shiyuDefense(bool $played, int $sRankStages = 0): GameEndgame
    {
        $endgame = new GameEndgame()
            ->setName('Shiyu Defense')
            ->setEndTime(self::end())
            ->setPlayed($played)
            ->setProgress($sRankStages)
            ->setMaxProgress(3)
            ->setProgressUnit('stages at S rank');

        if ($played) {
            $firstAtS = $sRankStages >= 3;
            $endgame
                ->addStage(new GameEndgameStage()->setName('Stage 1')->setScore($firstAtS ? 31000 : 24999)->setMaxScore(50000)->setResult($firstAtS ? 'S' : 'A')->setMaxed($firstAtS))
                ->addStage(new GameEndgameStage()->setName('Stage 2')->setScore(25000)->setMaxScore(50000)->setResult('S')->setMaxed(true))
                ->addStage(new GameEndgameStage()->setName('Stage 3')->setScore(49870)->setMaxScore(50000)->setResult('S')->setMaxed(true));
        }

        return $endgame;
    }

    private static function deadlyAssault(int $stars, bool $hardPlayed = false, int $hardStars = 0): array
    {
        $normal = new GameEndgame()
            ->setName('Deadly Assault')
            ->setEndTime(self::end())
            ->setPlayed($stars > 0)
            ->setProgress($stars)
            ->setMaxProgress(9)
            ->setProgressUnit('★')
            ->addStage(new GameEndgameStage()->setName('Example Boss')->setScore(19990)->setMaxScore(65000)->setResult('2★')->setMaxed($stars >= 9));

        $hard = new GameEndgame()
            ->setName('Deadly Assault (Hard)')
            ->setUnlocked($stars >= 9)
            ->setEndTime(self::end())
            ->setPlayed($hardPlayed)
            ->setProgress($hardStars)
            ->setMaxProgress(3)
            ->setProgressUnit('★');

        return [$normal, $hard];
    }

    /**
     * @return array<string, string> endgame fields of the only notification sent, by name
     */
    private function sentEndgameFields(): array
    {
        $this->assertCount(1, $this->sent);
        $this->assertSame('Zenless Zone Zero - Endgames Reminder', $this->sent[0]->getTitle());
        $this->assertSame("Don't Forget to Do Your Endgames!", $this->sent[0]->getDescription());

        $fields = [];
        foreach ($this->sent[0]->getFields() as $field) {
            /** @var EmbedField $field */
            if ($field->getIcon() === '⚔️') {
                $fields[$field->getName()] = $field->getValue();
            }
        }

        return $fields;
    }

    public function testRemindsAModeNotPlayedWithinTheWindow(): void
    {
        $handler = $this->createHandler($this->zenless(self::shiyuDefense(false)));

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame(
            ['Shiyu Defense' => "Not played yet\nEnds in 2 days, 12 hr (2026-03-20 03:59 UTC+01:00)"],
            $this->sentEndgameFields()
        );
    }

    public function testNoReminderBeforeTheWindow(): void
    {
        // 7 days and 13 hours before the end
        $handler = $this->createHandler($this->zenless(self::shiyuDefense(false)), now: '2026-03-12 15:00:00');

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->sent);
    }

    public function testRemindsOnTheLastDayAndNotAfterTheEnd(): void
    {
        $this->createHandler($this->zenless(self::shiyuDefense(false)), now: '2026-03-19 15:00:00')(new EndgamesReminderProfileMessage(1));
        $this->assertSame(['Shiyu Defense' => "Not played yet\nEnds in 12 hr (2026-03-20 03:59 UTC+01:00)"], $this->sentEndgameFields());

        $this->sent = [];
        $this->createHandler($this->zenless(self::shiyuDefense(false)), now: '2026-03-20 15:00:00')(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $this->sent);
    }

    public function testPlayedModeBelowTheMaximumOnlyWithFullClear(): void
    {
        $this->createHandler($this->zenless(self::shiyuDefense(true, 2)), fullClear: false)(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $this->sent);

        $this->createHandler($this->zenless(self::shiyuDefense(true, 2)), fullClear: true)(new EndgamesReminderProfileMessage(1));
        // Only the stage below the maximum is listed
        $this->assertSame(
            ['Shiyu Defense' => "2/3 stages at S rank\nStage 1: 24,999/50,000 (A)\nEnds in 2 days, 12 hr (2026-03-20 03:59 UTC+01:00)"],
            $this->sentEndgameFields()
        );
    }

    public function testNoNotificationWhenEverythingIsAtTheMaximum(): void
    {
        $handler = $this->createHandler(
            $this->zenless(self::shiyuDefense(true, 3), ...self::deadlyAssault(9, true, 3)),
            fullClear: true
        );

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->sent);
    }

    public function testLockedHardModeIsNotMentioned(): void
    {
        $handler = $this->createHandler($this->zenless(...self::deadlyAssault(8)), fullClear: true);

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame(
            ['Deadly Assault' => "8/9 ★\nExample Boss: 19,990/65,000 (2★)\nEnds in 2 days, 12 hr (2026-03-20 03:59 UTC+01:00)"],
            $this->sentEndgameFields()
        );
    }

    public function testUnlockedHardModeNotPlayedIsReminded(): void
    {
        $handler = $this->createHandler($this->zenless(...self::deadlyAssault(9)), fullClear: false);

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame(['Deadly Assault (Hard)'], array_keys($this->sentEndgameFields()));
    }

    public function testHardModeBelowThreeStarsWithFullClear(): void
    {
        $handler = $this->createHandler($this->zenless(...self::deadlyAssault(9, true, 2)), fullClear: true);

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame(
            ['Deadly Assault (Hard)' => "2/3 ★\nEnds in 2 days, 12 hr (2026-03-20 03:59 UTC+01:00)"],
            $this->sentEndgameFields()
        );
    }

    public function testUnknownEndIsLoggedAndSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('End of the "Shiyu Defense" period unknown'));

        $handler = $this->createHandler($this->zenless(self::shiyuDefense(false)->setEndTime(null)), logger: $logger);

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->sent);
    }

    public function testConfiguredDaysNarrowTheWindow(): void
    {
        $handler = $this->createHandler($this->zenless(self::shiyuDefense(false)));
        $handler->setDaysBeforeEnd(2);
        $handler(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $this->sent);

        $handler->setDaysBeforeEnd(3);
        $handler(new EndgamesReminderProfileMessage(1));
        $this->assertCount(1, $this->sent);
    }

    public function testGameWithoutEndgamesIsIgnored(): void
    {
        $handler = $this->createHandler($this->createStub(GameInterface::class));

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->sent);
    }

    public function testDefaultWindowIsSevenDays(): void
    {
        $handler = $this->createHandler($this->zenless());

        $this->assertSame(7, $handler->getDaysBeforeEnd());
    }
}

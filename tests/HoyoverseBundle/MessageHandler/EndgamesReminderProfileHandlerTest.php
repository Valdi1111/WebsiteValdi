<?php

namespace App\Tests\HoyoverseBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use App\CoreBundle\Service\Notification\TelegramHtmlRenderer;
use App\CoreBundle\Service\Notification\TelegramParsedown;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\Endgame\EndgameDefinition;
use App\GamesBundle\Model\Endgame\GameEndgame;
use App\GamesBundle\Presenter\EndgamesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Service\DurationFormatter;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Exception\RetrieveDeadlyAssaultDataException;
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
    /** End of the periods used below: 2026-03-20 03:59:59 server time (UTC+01:00) */
    private const string END = '2026-03-20 03:59:59';

    private const string FILLER = EndgamesPresenter::FILLER;

    /** @var list<UniversalEmbed> */
    private array $sent = [];

    /** @var list<string> keys of the modes requested from the game */
    private array $requested = [];

    private function createProfile(?array $settings): HoyoverseGameProfile
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
            ->setEndgamesSettings($settings)
            ->setNotificationPlatforms(['telegram']);
    }

    /**
     * @param array<string, mixed>|null $settings endgames_settings of the profile
     * @param array<string, mixed>      $config   hoyoverse.endgames_reminder
     */
    private function createHandler(
        object $gameService,
        ?array $settings = null,
        string $now = '2026-03-17 15:00:00',
        ?LoggerInterface $logger = null,
        array $config = ['zzz' => ['shiyu_defense' => ['days_before_end' => 7], 'deadly_assault' => ['days_before_end' => 7]]],
    ): EndgamesReminderProfileHandler {
        $repo = $this->createStub(HoyoverseGameProfileRepository::class);
        $repo->method('find')->willReturn($this->createProfile($settings));

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
        $handler->setEndgamesConfig($config);
        // The task runs at 15:00 of the regional timezone
        $clock = new MockClock($now, 'Europe/Paris');
        $handler->setClock($clock);
        $handler->setEndgamesPresenter(new EndgamesPresenter(new GameProfileEmbedFactory($clock), new DurationFormatter()));

        return $handler;
    }

    /**
     * Zenless Zone Zero with its real endgame declarations (final static methods) and the given results per mode.
     *
     * @param array<string, list<GameEndgame>|\Throwable> $results mode key => endgames, or the error to throw
     */
    private function zenless(array $results): ZenlessZoneZeroService
    {
        $service = $this->createStub(ZenlessZoneZeroService::class);
        $service->method('fetchEndgame')->willReturnCallback(function (EndgameDefinition $definition) use ($results): array {
            $this->requested[] = $definition->key;
            $result = $results[$definition->key] ?? [];
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return $result;
        });

        return $service;
    }

    private static function end(string $wallClock = self::END): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wallClock, new \DateTimeZone('+01:00'));
    }

    private static function shiyuDefense(bool $played, bool $maxed = false): array
    {
        $endgame = new GameEndgame()
            ->setName('Shiyu Defense')
            ->setEndTime(self::end())
            ->setPlayed($played)
            ->setMaxed($maxed);
        if ($played) {
            $endgame->addProgressLine('Floor 4: S', '⚔️')->addProgressLine($maxed ? 'Floor 5: 3/3 S' : 'Floor 5: 2/3 S', '⚔️');
            if (!$maxed) {
                $endgame->addProgressLine('↳ Stage 1: A (24,999)');
            }
        }

        return [$endgame];
    }

    /**
     * @return list<GameEndgame> normal mode, then Hard mode
     */
    private static function deadlyAssault(int $stars, bool $hardUnlocked = false, bool $hardPlayed = false, int $hardStars = 0): array
    {
        return [
            new GameEndgame()
                ->setName('Deadly Assault')
                ->setEndTime(self::end())
                ->setPlayed($stars > 0)
                ->setMaxed($stars >= 9)
                ->addProgressLine("{$stars}/9 ★"),
            new GameEndgame()
                ->setName('Deadly Assault (Hard)')
                ->setVariant('hard_reminder')
                ->setUnlocked($hardUnlocked)
                ->setEndTime(self::end())
                ->setPlayed($hardPlayed)
                ->setMaxed($hardStars >= 3)
                ->addProgressLine("{$hardStars}/3 ★"),
        ];
    }

    /**
     * @return list<array{0: ?string, 1: ?string, 2: ?string}> name, value and icon of the endgame fields of the only
     *                                                          notification sent (the player header excluded)
     */
    private function sentEndgameFields(): array
    {
        $this->assertCount(1, $this->sent);
        $this->assertSame('Zenless Zone Zero - Endgames Reminder', $this->sent[0]->getTitle());
        $this->assertSame("Don't Forget to Do Your Endgames!", $this->sent[0]->getDescription());

        $fields = array_slice($this->sent[0]->getFields(), 3);
        // Rows of three inline fields, so that Discord keeps each mode on its own line
        $this->assertSame(0, count($fields) % 3);
        foreach ($fields as $field) {
            $this->assertTrue($field->isInline());
        }

        return array_map(static fn (EmbedField $field) => [$field->getName(), $field->getValue(), $field->getIcon()], $fields);
    }

    public function testRemindsAModeNotPlayedWithinTheWindow(): void
    {
        $handler = $this->createHandler($this->zenless(['shiyu_defense' => self::shiyuDefense(false)]));

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([
            ['Shiyu Defense', 'Not played yet', '⚔️'],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
        ], $this->sentEndgameFields());
    }

    public function testNoReminderBeforeTheWindow(): void
    {
        // 7 days and 13 hours before the end
        $handler = $this->createHandler($this->zenless(['shiyu_defense' => self::shiyuDefense(false)]), now: '2026-03-12 15:00:00');

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->sent);
    }

    public function testRemindsOnTheLastDayAndNotAfterTheEnd(): void
    {
        $this->createHandler($this->zenless(['shiyu_defense' => self::shiyuDefense(false)]), now: '2026-03-19 15:00:00')(new EndgamesReminderProfileMessage(1));
        $this->assertSame(['Ends in', '12 hr, 59 min', '🕒'], $this->sentEndgameFields()[2]);

        $this->sent = [];
        $this->createHandler($this->zenless(['shiyu_defense' => self::shiyuDefense(false)]), now: '2026-03-20 15:00:00')(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $this->sent);
    }

    public function testPlayedModeBelowTheMaximumOnlyWithFullClear(): void
    {
        $this->createHandler($this->zenless(['shiyu_defense' => self::shiyuDefense(true)]))(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $this->sent);

        $this->createHandler(
            $this->zenless(['shiyu_defense' => self::shiyuDefense(true)]),
            ['shiyu_defense' => ['reminder' => 'full_clear']]
        )(new EndgamesReminderProfileMessage(1));
        // One line per floor with its own icon, so no icon for the whole field
        $this->assertSame([
            ['Shiyu Defense', "⚔️\u{00A0}\u{00A0}Floor 4: S\n⚔️\u{00A0}\u{00A0}Floor 5: 2/3 S\n↳ Stage 1: A (24,999)", null],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
        ], $this->sentEndgameFields());
    }

    public function testNoNotificationWhenEverythingIsAtTheMaximum(): void
    {
        $handler = $this->createHandler(
            $this->zenless(['shiyu_defense' => self::shiyuDefense(true, true), 'deadly_assault' => self::deadlyAssault(9, true, true, 3)]),
            ['shiyu_defense' => ['reminder' => 'full_clear'], 'deadly_assault' => ['reminder' => 'full_clear', 'hard_reminder' => 'full_clear']]
        );

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->sent);
    }

    public function testLockedHardModeLeavesAnEmptyColumn(): void
    {
        $handler = $this->createHandler(
            $this->zenless(['deadly_assault' => self::deadlyAssault(8)]),
            ['deadly_assault' => ['reminder' => 'full_clear', 'hard_reminder' => 'full_clear']]
        );

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([
            ['Deadly Assault', '8/9 ★', '⚔️'],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
        ], $this->sentEndgameFields());
    }

    public function testHardModeIsOffByDefault(): void
    {
        $results = ['deadly_assault' => self::deadlyAssault(9, hardUnlocked: true)];

        $this->createHandler($this->zenless($results))(new EndgamesReminderProfileMessage(1));
        $this->assertSame([], $this->sent);

        $this->createHandler($this->zenless($results), ['deadly_assault' => ['hard_reminder' => 'not_played']])(new EndgamesReminderProfileMessage(1));
        // The completed normal mode stays in the row, marked as done
        $this->assertSame([
            ['Deadly Assault', '9/9 ★', '✅'],
            ['Deadly Assault (Hard)', 'Not played yet', '⚔️'],
            ['Ends in', '2 days, 12 hr', '🕒'],
        ], $this->sentEndgameFields());
    }

    public function testHardModeBelowThreeStarsWithFullClear(): void
    {
        $handler = $this->createHandler(
            $this->zenless(['deadly_assault' => self::deadlyAssault(9, true, true, 2)]),
            ['deadly_assault' => ['hard_reminder' => 'full_clear']]
        );

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame(['Deadly Assault (Hard)', '2/3 ★', '⚔️'], $this->sentEndgameFields()[1]);
    }

    public function testOneNotificationWithARowPerMode(): void
    {
        $handler = $this->createHandler($this->zenless([
            'shiyu_defense' => self::shiyuDefense(false),
            'deadly_assault' => self::deadlyAssault(0),
        ]));

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([
            ['Shiyu Defense', 'Not played yet', '⚔️'],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
            ['Deadly Assault', 'Not played yet', '⚔️'],
            [self::FILLER, self::FILLER, null],
            ['Ends in', '2 days, 12 hr', '🕒'],
        ], $this->sentEndgameFields());
    }

    public function testModesTurnedOffAreNotRequested(): void
    {
        $handler = $this->createHandler(
            $this->zenless(['shiyu_defense' => self::shiyuDefense(false), 'deadly_assault' => self::deadlyAssault(0)]),
            ['shiyu_defense' => ['reminder' => 'off']]
        );

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame(['deadly_assault'], $this->requested);
        $this->assertSame('Deadly Assault', $this->sentEndgameFields()[0][0]);
    }

    public function testEveryModeOffRequestsNothing(): void
    {
        $handler = $this->createHandler(
            $this->zenless(['shiyu_defense' => new \RuntimeException('must not be requested')]),
            ['shiyu_defense' => ['reminder' => 'off'], 'deadly_assault' => ['reminder' => 'off', 'hard_reminder' => 'off']]
        );

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->requested);
        $this->assertSame([], $this->sent);
    }

    public function testFailingModeIsSkippedAndTheOthersAreReminded(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('Deadly Assault could not be read'),
                $this->stringContains(RetrieveDeadlyAssaultDataException::class),
                $this->logicalNot($this->stringContains('secret'))
            ));

        $handler = $this->createHandler($this->zenless([
            'shiyu_defense' => self::shiyuDefense(false),
            'deadly_assault' => new RetrieveDeadlyAssaultDataException('secret body'),
        ]), logger: $logger);

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame('Shiyu Defense', $this->sentEndgameFields()[0][0]);
    }

    public function testErrorIsThrownWhenEveryRequestedModeFails(): void
    {
        // Shiyu Defense is off: the only requested mode fails
        $handler = $this->createHandler(
            $this->zenless(['deadly_assault' => new RetrieveDeadlyAssaultDataException('expired cookie')]),
            ['shiyu_defense' => ['reminder' => 'off']]
        );

        $this->expectException(RetrieveDeadlyAssaultDataException::class);
        $handler(new EndgamesReminderProfileMessage(1));
    }

    public function testUnknownEndIsLoggedAndSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('End of the "Shiyu Defense" period unknown'));

        $shiyu = self::shiyuDefense(false);
        $shiyu[0]->setEndTime(null);
        $handler = $this->createHandler($this->zenless(['shiyu_defense' => $shiyu]), logger: $logger);

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->sent);
    }

    public function testEachModeHasItsOwnWindow(): void
    {
        // 2 days and 12 hours before both ends: inside the 7-day window, outside the 2-day one
        $handler = $this->createHandler(
            $this->zenless(['shiyu_defense' => self::shiyuDefense(false), 'deadly_assault' => self::deadlyAssault(0)]),
            config: ['zzz' => ['shiyu_defense' => ['days_before_end' => 7], 'deadly_assault' => ['days_before_end' => 2]]]
        );

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame(['Shiyu Defense', self::FILLER, 'Ends in'], array_column($this->sentEndgameFields(), 0));
    }

    public function testDefaultWindowWithoutConfiguration(): void
    {
        $handler = $this->createHandler($this->zenless([]), config: []);

        $this->assertSame(7, $handler->getDaysBeforeEnd('zzz', ZenlessZoneZeroService::getEndgameDefinitions()[0]));
    }

    public function testGameWithoutEndgamesIsIgnored(): void
    {
        $handler = $this->createHandler($this->createStub(GameInterface::class));

        $handler(new EndgamesReminderProfileMessage(1));

        $this->assertSame([], $this->sent);
    }

    /**
     * The notification as Telegram and Discord render it: empty fields skipped by Telegram, kept by Discord to align
     * the rows.
     */
    public function testNotificationOnTelegramAndDiscord(): void
    {
        $this->createHandler(
            $this->zenless(['shiyu_defense' => self::shiyuDefense(true), 'deadly_assault' => self::deadlyAssault(9, true)]),
            ['shiyu_defense' => ['reminder' => 'full_clear'], 'deadly_assault' => ['hard_reminder' => 'not_played']]
        )(new EndgamesReminderProfileMessage(1));
        $this->assertCount(1, $this->sent);

        $telegram = new TelegramHtmlRenderer(new TelegramParsedown())->render($this->sent[0]);
        $this->assertStringContainsString(
            "<b>Shiyu Defense:</b> ⚔️\u{00A0}\u{00A0}Floor 4: S\n⚔️\u{00A0}\u{00A0}Floor 5: 2/3 S\n↳ Stage 1: A (24,999)\n"
            . "<b>🕒 Ends in:</b> 2 days, 12 hr\n"
            . "<b>✅ Deadly Assault:</b> 9/9 ★\n"
            . "<b>⚔️ Deadly Assault (Hard):</b> Not played yet\n"
            . "<b>🕒 Ends in:</b> 2 days, 12 hr",
            $telegram
        );
        $this->assertStringNotContainsString(self::FILLER, $telegram);

        $discordFields = array_slice(new DiscordEmbedTransformer()->transform($this->sent[0])->toArray()['fields'], 3);
        $this->assertSame(
            ['Shiyu Defense', self::FILLER, 'Ends in', 'Deadly Assault', 'Deadly Assault (Hard)', 'Ends in'],
            array_column($discordFields, 'name')
        );
        $this->assertSame("✅\u{00A0}\u{00A0}9/9 ★", $discordFields[3]['value']);
        $this->assertSame([true], array_values(array_unique(array_column($discordFields, 'inline'))));
    }
}

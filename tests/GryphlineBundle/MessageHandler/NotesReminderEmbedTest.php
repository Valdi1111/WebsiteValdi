<?php

namespace App\Tests\GryphlineBundle\MessageHandler;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Notification\UnifiedNotificationService;
use App\GamesBundle\Model\GameNotes\GameNotesDailies;
use App\GamesBundle\Model\GameNotes\GameNotesMetricCheckType;
use App\GamesBundle\Model\GameNotes\GameNotesProgressMetric;
use App\GamesBundle\Model\GameNotes\GameNotesStamina;
use App\GamesBundle\Model\GameNotes\GameNotesWeeklies;
use App\GamesBundle\Presenter\DailiesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\StaminaPresenter;
use App\GamesBundle\Presenter\WeekliesPresenter;
use App\GamesBundle\Service\DurationFormatter;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Message\Profile\DailiesReminderProfileMessage;
use App\GryphlineBundle\Message\Profile\StaminaCheckProfileMessage;
use App\GryphlineBundle\Message\Profile\WeekliesReminderProfileMessage;
use App\GryphlineBundle\MessageHandler\Profile\AbstractProfileTaskMessageHandler;
use App\GryphlineBundle\MessageHandler\Profile\DailiesReminderProfileHandler;
use App\GryphlineBundle\MessageHandler\Profile\StaminaCheckProfileHandler;
use App\GryphlineBundle\MessageHandler\Profile\WeekliesReminderProfileHandler;
use App\GryphlineBundle\Repository\GryphlineGameProfileRepository;
use App\GryphlineBundle\Service\EndfieldService;
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
        ['Player', '(`123456789`) Endministrator', '👤', true],
        ['Region', 'Asia', '🌍', true],
        ['Rank', '40', '🏆', true],
    ];

    /** @var list<UniversalEmbed> */
    private array $sent = [];

    private function createProfile(): GryphlineGameProfile
    {
        $account = new GryphlineAccount();
        $account->setUser(new User());

        return new GryphlineGameProfile()
            ->setAccount($account)
            ->setGameBiz('endfield')
            ->setGameUid('123456789')
            ->setGameName('Arknights: Endfield')
            ->setNickname('Endministrator')
            ->setRegion('Asia')
            ->setLevel(40)
            ->setNotificationPlatforms(['telegram']);
    }

    /**
     * @template H of AbstractProfileTaskMessageHandler
     *
     * @param class-string<H> $class
     *
     * @return H
     */
    private function createHandler(string $class, GryphlineGameProfile $profile, object $gameService): AbstractProfileTaskMessageHandler
    {
        $repo = $this->createStub(GryphlineGameProfileRepository::class);
        $repo->method('find')->willReturn($profile);

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($gameService);

        $notificationService = $this->createStub(UnifiedNotificationService::class);
        $notificationService->method('sendToUser')->willReturnCallback(function (User $user, UniversalEmbed $embed, array $platforms): void {
            $this->assertSame(['telegram'], $platforms);
            $this->sent[] = $embed;
        });

        $handler = new $class($repo, $locator, new NullLogger(), $notificationService);

        $embedFactory = new GameProfileEmbedFactory(new MockClock(self::NOW, 'UTC'));
        match (true) {
            $handler instanceof StaminaCheckProfileHandler => $handler->setStaminaPresenter(new StaminaPresenter($embedFactory, new DurationFormatter())),
            $handler instanceof DailiesReminderProfileHandler => $handler->setDailiesPresenter(new DailiesPresenter($embedFactory)),
            $handler instanceof WeekliesReminderProfileHandler => $handler->setWeekliesPresenter(new WeekliesPresenter($embedFactory)),
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
        $profile = $this->createProfile()->setStaminaCheck(true)->setStaminaThreshold(200);
        $service = $this->createStub(EndfieldService::class);
        $service->method('getStaminaData')->willReturn(new GameNotesStamina()->setCurrentStamina(250)->setMaxStamina(360)->setStaminaRecoverTime(90061));

        $this->createHandler(StaminaCheckProfileHandler::class, $profile, $service)(new StaminaCheckProfileMessage(1));

        $this->assertSame([
            'title' => 'Arknights: Endfield - Stamina Reminder',
            'description' => 'Your stamina is within the set threshold!',
            'color' => 0x3498DB,
            'footer' => ['Arknights: Endfield', 'https://game.skport.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Stamina', '250/360', '🔋', true],
                ['Recovery Time', '1 day, 1 hr, 1 min, 1 sec', '🕒', true],
            ],
        ], $this->sentEmbed());
    }

    public function testDailiesNotDone(): void
    {
        $dailies = new GameNotesDailies()
            ->addDaily(new GameNotesProgressMetric()->setName('Activity')->setCurrentValue(60)->setMaxValue(100))
            ->addDaily(new GameNotesProgressMetric()
                ->setName('Daily Missions')
                ->setCurrentValue(2)
                ->setMaxValue(5)
                ->setCheckType(GameNotesMetricCheckType::CURRENT_EQUALS_ZERO));
        $service = $this->createStub(EndfieldService::class);
        $service->method('getDailiesData')->willReturn($dailies);

        $this->createHandler(DailiesReminderProfileHandler::class, $this->createProfile(), $service)(new DailiesReminderProfileMessage(1));

        $this->assertSame([
            'title' => 'Arknights: Endfield - Dailies Reminder',
            'description' => "Don't Forget to Do Your Dailies!",
            'color' => 0x3498DB,
            'footer' => ['Arknights: Endfield', 'https://game.skport.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Activity', '60/100', '📅', true],
                ['Daily Missions', '3/5', '📅', true],
            ],
        ], $this->sentEmbed());
    }

    public function testWeekliesNotDone(): void
    {
        $weeklies = new GameNotesWeeklies()
            ->addWeekly(new GameNotesProgressMetric()->setName('Weekly Routine')->setCurrentValue(4)->setMaxValue(10));
        $service = $this->createStub(EndfieldService::class);
        $service->method('getWeekliesData')->willReturn($weeklies);

        $this->createHandler(WeekliesReminderProfileHandler::class, $this->createProfile(), $service)(new WeekliesReminderProfileMessage(1));

        $this->assertSame([
            'title' => 'Arknights: Endfield - Weeklies Reminder',
            'description' => "Don't Forget to Do Your Weeklies!",
            'color' => 0x3498DB,
            'footer' => ['Arknights: Endfield', 'https://game.skport.com/favicon.ico'],
            'fields' => [
                ...self::PLAYER_FIELDS,
                ['Weekly Routine', '4/10', '📊', true],
            ],
        ], $this->sentEmbed());
    }
}

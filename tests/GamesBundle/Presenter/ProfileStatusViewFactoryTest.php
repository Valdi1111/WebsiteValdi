<?php

namespace App\Tests\GamesBundle\Presenter;

use App\CoreBundle\Model\Notification\EmbedField;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Model\Profile\AccountHealth;
use App\GamesBundle\Model\Profile\GameDescriptor;
use App\GamesBundle\Model\Profile\GameProfileContext;
use App\GamesBundle\Model\Profile\GameProfileRef;
use App\GamesBundle\Model\Status\GameProfileStatus;
use App\GamesBundle\Model\Status\ProfileSectionInterface;
use App\GamesBundle\Model\Status\SectionResult;
use App\GamesBundle\Model\Status\SummaryLine;
use App\GamesBundle\Model\Status\View\ProfileStatusFieldView;
use App\GamesBundle\Presenter\EndgamesPresenter;
use App\GamesBundle\Presenter\GameProfileEmbedFactory;
use App\GamesBundle\Presenter\ProfileStatusViewFactory;
use App\GamesBundle\Presenter\ProfileSummaryPresenter;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

class ProfileStatusViewFactoryTest extends TestCase
{
    private const string NOW = '2026-11-25 10:00:00';

    private GameProfileEmbedFactory $embedFactory;
    private FakeGameProfile $profile;

    /** @var list<array{string, array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->embedFactory = new GameProfileEmbedFactory(new MockClock(self::NOW));
        $this->profile = new FakeGameProfile(5);
    }

    private function factory(): ProfileStatusViewFactory
    {
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{string, array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string) $message, $context];
            }
        };

        return new ProfileStatusViewFactory($this->embedFactory, $logger);
    }

    /**
     * Section whose views come from closures, with the header of the real embed factory.
     */
    private function section(string $key, ?\Closure $summarize = null, ?\Closure $present = null): ProfileSectionInterface
    {
        $embedFactory = $this->embedFactory;
        $summarize ??= static fn (): SummaryLine => new SummaryLine(ucfirst($key), '3/4', '📅', true);
        $present ??= static fn (GameProfileInterface $profile): UniversalEmbed => $embedFactory->create($profile, ucfirst($key), "Note of $key")
            ->addField('Done', '3/4', icon: '✅', inline: true)
            // Completes the row on Discord only
            ->addEmbedField(new EmbedField()->setName(EndgamesPresenter::FILLER)->setValue(EndgamesPresenter::FILLER)->setInline(true))
            ->addField('Left', '1');

        return new class($key, $summarize, $present) implements ProfileSectionInterface {
            public function __construct(private readonly string $key, private readonly \Closure $summarize, private readonly \Closure $present)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function getLabel(): string
            {
                return ucfirst($this->key);
            }

            public function getIcon(): string
            {
                return '📅';
            }

            public function supports(GameProfileContext $context): bool
            {
                return true;
            }

            public function usesNotes(): bool
            {
                return false;
            }

            public function inSummary(): bool
            {
                return true;
            }

            public function collect(GameProfileContext $context): object
            {
                return new \stdClass();
            }

            public function present(GameProfileInterface $profile, object $data): UniversalEmbed
            {
                return ($this->present)($profile, $data);
            }

            public function summarize(GameProfileInterface $profile, object $data): SummaryLine
            {
                return ($this->summarize)($profile, $data);
            }
        };
    }

    /**
     * @param array<string, SectionResult> $sections
     */
    private function profileStatus(array $sections, ?\DateTimeImmutable $fetchedAt = null, ?\Throwable $error = null, ?AccountHealth $health = null): GameProfileStatus
    {
        return new GameProfileStatus(
            $this->profile,
            new GameProfileRef('fake', 5),
            new GameDescriptor('fake', 'fake_game', 'fg', 'Fake Game'),
            $health ?? AccountHealth::ok(),
            $sections,
            $fetchedAt,
            $error,
        );
    }

    public function testEmbedHeaderHasTheFieldsTheViewDrops(): void
    {
        $fields = $this->embedFactory->create($this->profile, 'Any')->getFields();

        $this->assertCount(GameProfileEmbedFactory::HEADER_FIELD_COUNT, $fields);
        $this->assertSame(['Player', 'Region', 'Rank'], array_map(static fn ($field): ?string => $field->getName(), $fields));
    }

    public function testSectionsShowTheSummaryLineAndTheFullViewWithoutHeaderNorFillers(): void
    {
        $section = $this->section('dailies');
        $fetchedAt = new \DateTimeImmutable('2026-11-25 09:50:00');

        $view = $this->factory()->create($this->profileStatus(['dailies' => SectionResult::success($section, new \stdClass())], $fetchedAt));

        $this->assertSame('fake:5', $view->ref);
        $this->assertSame(['slug' => 'fg', 'name' => 'Fake Game'], $view->game);
        $this->assertEquals($this->embedFactory->now(), $view->generatedAt);
        $this->assertSame($fetchedAt, $view->fetchedAt);
        $this->assertTrue($view->account->ok);
        $this->assertNull($view->error);

        $this->assertCount(1, $view->sections);
        $dailies = $view->sections[0];
        $this->assertSame(['dailies', 'Dailies', '📅', true, true, '3/4', 'Note of dailies'], [
            $dailies->key, $dailies->label, $dailies->icon, $dailies->available, $dailies->attention, $dailies->value, $dailies->description,
        ]);
        $this->assertEquals([
            new ProfileStatusFieldView('Done', '3/4', '✅', true),
            new ProfileStatusFieldView('Left', '1'),
        ], $dailies->fields);
    }

    public function testFailedSectionAndProfileAreGeneric(): void
    {
        $section = $this->section('dailies');

        $view = $this->factory()->create($this->profileStatus(['dailies' => SectionResult::failure($section, new \RuntimeException('cookie=secret'))]));
        $this->assertFalse($view->sections[0]->available);
        $this->assertFalse($view->sections[0]->attention);
        $this->assertSame(ProfileSummaryPresenter::UNAVAILABLE, $view->sections[0]->value);
        $this->assertSame([], $view->sections[0]->fields);
        $this->assertNull($view->sections[0]->description);

        $view = $this->factory()->create($this->profileStatus([], error: new \RuntimeException('cookie=secret'), health: AccountHealth::problem('Log in again.')));
        $this->assertSame(ProfileSummaryPresenter::PROFILE_UNAVAILABLE, $view->error);
        $this->assertSame([], $view->sections);
        $this->assertSame('Log in again.', $view->account->problem);
    }

    public function testSectionThatCannotBeShownIsUnavailableAndLoggedWithoutMessage(): void
    {
        $throws = static fn (): never => throw new \LogicException('value=secret', 7);
        $sections = [
            'broken_line' => SectionResult::success($this->section('broken_line', summarize: $throws), new \stdClass()),
            'broken_view' => SectionResult::success($this->section('broken_view', present: $throws), new \stdClass()),
            'dailies' => SectionResult::success($this->section('dailies'), new \stdClass()),
        ];

        $view = $this->factory()->create($this->profileStatus($sections));

        $this->assertSame([false, false, true], array_map(static fn ($section): bool => $section->available, $view->sections));
        $this->assertSame(ProfileSummaryPresenter::UNAVAILABLE, $view->sections[1]->value);
        $this->assertCount(2, $this->logs);
        $this->assertSame(['profile' => 'fake:5', 'section' => 'broken_line', 'error' => \LogicException::class, 'code' => 7], $this->logs[0][1]);
        $this->assertStringNotContainsString('secret', serialize($this->logs));
    }
}

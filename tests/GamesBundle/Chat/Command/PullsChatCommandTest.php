<?php

namespace App\Tests\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Chat\ChatChoice;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatOption;
use App\CoreBundle\Model\Chat\ChatOptionType;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Chat\ChatProfileSelector;
use App\GamesBundle\Chat\Command\AbstractProfileChatCommand;
use App\GamesBundle\Chat\Command\PullsChatCommand;
use App\GamesBundle\Exception\PullCardUnavailableException;
use App\GamesBundle\Exception\TooManyPullCardsException;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Pull\Card\PullCardBuilderRegistry;
use App\GamesBundle\Service\Pull\Card\PullCardIconInliner;
use App\GamesBundle\Service\Pull\Card\PullCardLimiter;
use App\GamesBundle\Service\Pull\Card\PullCardRenderer;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\Tests\GamesBundle\Chat\Fixtures\GamesChatWorld;
use App\Tests\GamesBundle\Service\Pull\Card\Fixtures\FakePullCardBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * /pulls over the profiles of GamesChatWorld, with the real selector, registry, renderer (node-services answering
 * with a PNG) and limiter: "full_game" and "checkin_game" have a pull tracker on provider "fake", "other_game" on
 * provider "other", "plain_game" has none.
 */
class PullsChatCommandTest extends TestCase
{
    private const string GAMES_DOMAIN = 'games.example.test';
    private const int CARD_LIMIT = 2;
    /** Size of the PNG node-services answers with */
    private const int PNG_WIDTH = 24;
    private const int PNG_HEIGHT = 16;

    private GamesChatWorld $world;
    private FakePullCardBuilder $builder;
    private FakePullCardBuilder $otherBuilder;
    private PullCardLimiter $limiter;
    private PullsChatCommand $command;
    private User $user;

    /** HTTP status of node-services */
    private int $nodeServicesStatus = 200;
    /** @var list<int> scales asked to node-services */
    private array $scales = [];

    protected function setUp(): void
    {
        $this->world = new GamesChatWorld();
        $this->builder = new FakePullCardBuilder(['full_game', 'checkin_game']);
        $this->otherBuilder = new class(['other_game']) extends FakePullCardBuilder {
            public static function getProviderKey(): string
            {
                return 'other';
            }
        };
        $this->otherBuilder->banners = [['key' => 'collab', 'name' => 'Collab Hunt'], ['key' => 'basic', 'name' => 'Basic Hunt']];

        $roleResolver = $this->createStub(UserRoleResolver::class);
        $roleResolver->method('getReachableRoleNames')->willReturnCallback(static fn (User $user): array => $user->getRoles());
        $profiles = new GameProfileRegistry(
            [$this->world->fake, $this->world->other],
            $roleResolver,
            ['fake' => GamesChatWorld::ROLE_FAKE, 'other' => GamesChatWorld::ROLE_OTHER],
        );

        $this->limiter = new PullCardLimiter(new RateLimiterFactory([
            'id' => 'games_pull_card',
            'policy' => 'sliding_window',
            'limit' => self::CARD_LIMIT,
            'interval' => '10 minutes',
        ], new InMemoryStorage()));

        $this->command = new PullsChatCommand(
            // No section asked by /pulls: the collector is never read
            new ChatProfileSelector($profiles, $this->createStub(GameProfileStatusCollector::class), self::GAMES_DOMAIN),
            new PullCardBuilderRegistry([$this->builder, $this->otherBuilder], $profiles),
            $this->createRenderer(),
            $this->limiter,
        );
        $this->user = GamesChatWorld::user(42);
    }

    /**
     * @param array<string, string|int|bool> $arguments
     */
    private function execute(array $arguments = [], ?User $user = null): ChatReply
    {
        $user ??= $this->user;

        return $this->command->execute(new ChatRequest(ChatPlatform::Discord, new ChatUser($user, ChatPlatform::Discord, 'd'.$user->getId()), 'pulls', null, $arguments));
    }

    /**
     * @param array<string, string|int|bool> $arguments
     *
     * @return array<string, string|int> value by name
     */
    private function suggest(string $option, string $partial = '', array $arguments = [], ?User $user = null): array
    {
        $user ??= $this->user;
        $choices = $this->command->autocomplete(new ChatUser($user, ChatPlatform::Discord, 'd'.$user->getId()), null, $option, $partial, $arguments);

        return array_column(array_map(static fn (ChatChoice $choice): array => ['name' => $choice->name, 'value' => $choice->value], $choices), 'value', 'name');
    }

    /**
     * @return list<?string> the banner key of each card built
     */
    private function bannerKeysAsked(): array
    {
        return array_column($this->builder->calls, 'bannerKey');
    }

    /**
     * Nelly on Full Game, a profile of Checkin Game (both with pulls), one of Plain Game (none).
     */
    private function threeGames(): void
    {
        $this->world->profile($this->user, 1, nickname: 'Nelly', uid: '700000001');
        $this->world->profile($this->user, 2, 'checkin_game');
        $this->world->profile($this->user, 3, 'plain_game');
    }

    public function testCommandIsPublicForTheGamesRoleWithItsOptions(): void
    {
        $attribute = new \ReflectionClass(PullsChatCommand::class)->getAttributes(AsChatCommand::class)[0]->newInstance();
        $this->assertSame('pulls', $attribute->name);
        $this->assertSame(AbstractProfileChatCommand::ROLE, $attribute->role);
        $this->assertFalse($attribute->ephemeral, 'Made to be shown');

        $builder = new ChatCommandBuilder($attribute->name, $attribute->description, $attribute->role, $attribute->ephemeral);
        $this->command->configure($builder);

        $this->assertSame(
            [
                ['game', ChatOptionType::String, false, true],
                ['profile', ChatOptionType::String, false, true],
                ['banner', ChatOptionType::String, false, true],
                ['all', ChatOptionType::Boolean, false, false],
                ['player', ChatOptionType::Boolean, false, false],
            ],
            array_map(static fn (ChatOption $option): array => [$option->name, $option->type, $option->required, $option->autocomplete], $builder->getDefinition()->options),
        );
    }

    public function testTheOnlyProfileWithPullsIsSentAsAWebpImage(): void
    {
        $profile = $this->world->profile($this->user, 1, nickname: 'Nelly', uid: '700000001');
        $this->world->profile($this->user, 3, 'plain_game');

        $reply = $this->execute();

        $this->assertSame('Full Game · All banners', $reply->text);
        $this->assertSame([], $reply->embeds);
        $this->assertSame([], $reply->actionRows);
        $image = $reply->image;
        $this->assertNotNull($image);
        $this->assertSame('full-game-all-banners.webp', $image->filename);
        $this->assertSame('image/webp', $image->contentType);
        $this->assertSame([self::PNG_WIDTH, self::PNG_HEIGHT], [$image->width, $image->height]);
        $this->assertSame('Full Game · All banners', $image->description, 'The alt text');
        $this->assertSame(['RIFF', 'WEBP'], [substr($image->content, 0, 4), substr($image->content, 8, 4)], 'A WebP file');

        $this->assertCount(1, $this->builder->calls);
        $this->assertSame(['profile' => $profile, 'bannerKey' => null, 'showAll' => false, 'showPlayer' => false], $this->builder->calls[0]);
        $this->assertSame([2], $this->scales, 'Rendered at 2x');
        $this->assertSame([], $this->otherBuilder->calls);
    }

    public function testSeveralProfilesWithPullsAreRefusedWithTheirGamesOnly(): void
    {
        $this->threeGames();

        // Public on Discord: the games, never a nickname nor a UID
        $text = (string) $this->execute()->text;
        $this->assertSame('⚠️ Several of your profiles have pulls (Full Game, Checkin Game). Choose one with the game or profile option.', $text);
        $this->assertStringNotContainsString('Nelly', $text);
        $this->assertStringNotContainsString('700000001', $text);
        $this->assertSame([], $this->builder->calls);
        $this->assertSame([], $this->scales);
    }

    public function testGameOrProfileOptionChoosesAmongTheProfilesWithPulls(): void
    {
        $this->threeGames();

        $this->assertSame('Checkin Game · All banners', $this->execute(['game' => 'ci'])->text);
        $this->assertSame('Full Game · All banners', $this->execute(['profile' => 'nelly'])->text);
        $this->assertSame([2, 1], array_map(static fn (array $call): ?int => $call['profile']->getId(), $this->builder->calls));
    }

    public function testNoProfileWithPullsIsRefused(): void
    {
        $this->assertSame(sprintf('⚠️ You have no active game profile yet. Add one on https://%s/', self::GAMES_DOMAIN), $this->execute()->text);

        $this->world->profile($this->user, 3, 'plain_game');
        $this->assertSame('⚠️ None of your profiles has a pulls tracker.', $this->execute()->text);
        $this->assertSame('⚠️ None of your profiles has a pulls tracker.', $this->execute(['game' => 'plain'])->text);
        $this->assertSame([], $this->builder->calls);
    }

    public function testProfileWithoutPullTrackerIsRefused(): void
    {
        $this->threeGames();

        $this->assertSame('⚠️ This profile has no pulls tracker.', $this->execute(['profile' => 'fake:3'])->text);
        // The refusals of the selector are passed on
        $this->assertSame('⚠️ Unknown game "hsr". Your games: full, ci, plain.', $this->execute(['game' => 'hsr'])->text);
        $this->assertSame('⚠️ '.ChatProfileSelector::NOT_FOUND, $this->execute(['profile' => 'fake:9'])->text);
        $this->assertSame([], $this->builder->calls);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function bannerProvider(): iterable
    {
        yield 'key' => ['weapon', 'weapon', 'Weapon Event'];
        yield 'key ignoring case' => ['WEAPON', 'weapon', 'Weapon Event'];
        yield 'numeric key' => ['200', '200', 'Standard'];
        yield 'name ignoring case' => ['CHARACTER EVENT', 'char', 'Character Event'];
        yield 'name with spaces around' => ['  standard ', '200', 'Standard'];
        yield 'part of one name only' => ['chara', 'char', 'Character Event'];
        yield 'part of one name, with a space' => ['pon ev', 'weapon', 'Weapon Event'];
    }

    #[DataProvider('bannerProvider')]
    public function testBannerByKeyOrName(string $typed, string $expectedKey, string $expectedTitle): void
    {
        $this->world->profile($this->user, 1);

        $reply = $this->execute(['banner' => $typed]);

        $this->assertSame('Full Game · '.$expectedTitle, $reply->text);
        $this->assertSame([$expectedKey], $this->bannerKeysAsked());
        $this->assertSame(sprintf('full-game-%s.webp', strtolower(str_replace(' ', '-', $expectedTitle))), $reply->image?->filename);
    }

    public function testUnknownBannerListsTheBannersWithoutCountingACard(): void
    {
        $this->world->profile($this->user, 1);

        for ($i = 0; $i <= self::CARD_LIMIT; ++$i) {
            $this->assertSame(
                '⚠️ Unknown banner "no such banner". Banners of Full Game: Character Event, Weapon Event, Standard.',
                $this->execute(['banner' => 'no such banner'])->text,
            );
        }
        // What the user typed is repeated within reason
        $long = trim(str_repeat('ab ', 30));
        $this->assertSame(
            sprintf('⚠️ Unknown banner "%s". Banners of Full Game: Character Event, Weapon Event, Standard.', mb_substr($long, 0, 50)),
            $this->execute(['banner' => $long])->text,
        );
        $this->assertSame([], $this->builder->calls);
        $this->assertSame([], $this->scales);

        // None of them was counted
        $this->assertNotNull($this->execute()->image);
    }

    public function testKeyLikeBannerOfNoNameIsAskedAsTyped(): void
    {
        $this->world->profile($this->user, 1);
        // A gacha type missing from the configuration, with pulls
        $this->builder->unconfiguredBanners = ['999' => 'Mystery Wish'];

        $this->assertSame('Full Game · Mystery Wish', $this->execute(['banner' => '999'])->text);
        // Part of two names: no guess, asked as typed and refused by the builder
        $this->assertSame(
            '⚠️ Unknown banner "event". Banners of Full Game: Character Event, Weapon Event, Standard.',
            $this->execute(['banner' => 'event'])->text,
        );
        $this->assertSame(['999', 'event'], $this->bannerKeysAsked());
    }

    public function testAllAndPlayerOptionsReachTheBuilder(): void
    {
        $this->world->profile($this->user, 1);

        $this->execute(['all' => true, 'player' => true, 'banner' => 'char']);
        $this->execute(['all' => false, 'player' => false]);

        $this->assertSame(
            [['char', true, true], [null, false, false]],
            array_map(static fn (array $call): array => [$call['bannerKey'], $call['showAll'], $call['showPlayer']], $this->builder->calls),
        );
    }

    public function testTooManyCardsAreRefusedWithoutRenderingOne(): void
    {
        $this->world->profile($this->user, 1);

        for ($i = 1; $i <= self::CARD_LIMIT; ++$i) {
            $this->assertNotNull($this->execute()->image, sprintf('Card %d', $i));
        }
        $reply = $this->execute(['banner' => 'char']);

        $this->assertSame('⚠️ '.TooManyPullCardsException::MESSAGE, $reply->text);
        $this->assertNull($reply->image);
        // The card is built (cheap, it checks the banner), the render it would cost is not asked for
        $this->assertCount(self::CARD_LIMIT + 1, $this->builder->calls);
        $this->assertCount(self::CARD_LIMIT, $this->scales, 'No card rendered beyond the limit');
    }

    public function testAMistypedKeyLikeBannerCostsNoCard(): void
    {
        $this->world->profile($this->user, 1);

        for ($i = 1; $i <= self::CARD_LIMIT + 1; ++$i) {
            $reply = $this->execute(['banner' => 'nosuchkey']);
            $this->assertStringContainsString('Unknown banner "nosuchkey".', (string) $reply->text, sprintf('Attempt %d', $i));
        }
        $this->assertSame([], $this->scales);
        $this->assertNotNull($this->execute()->image, 'The budget is untouched');
    }

    public function testCardsCountedOnTheSiteCountForTheCommandToo(): void
    {
        $this->world->profile($this->user, 1);
        for ($i = 1; $i <= self::CARD_LIMIT; ++$i) {
            $this->limiter->consume($this->user);
        }

        $this->assertSame('⚠️ '.TooManyPullCardsException::MESSAGE, $this->execute()->text);
        $this->assertSame([], $this->scales, 'Nothing rendered');
        // Each user has a budget of their own
        $someoneElse = GamesChatWorld::user(43);
        $this->world->profile($someoneElse, 9);
        $this->assertNotNull($this->execute(user: $someoneElse)->image);
    }

    public function testUnavailableRendererIsReportedToTheUser(): void
    {
        $this->world->profile($this->user, 1);
        $this->nodeServicesStatus = 500;

        $reply = $this->execute();

        $this->assertSame('⚠️ '.PullCardUnavailableException::MESSAGE, $reply->text);
        $this->assertNull($reply->image);
        $this->assertCount(1, $this->builder->calls);
    }

    public function testRefusalOfTheBuilderWithoutBannerIsReportedAsIs(): void
    {
        $this->world->profile($this->user, 1);
        // The profile changed meanwhile
        $this->builder->error = new \InvalidArgumentException('This game profile does not support pull tracking.');

        $this->assertSame('⚠️ This game profile does not support pull tracking.', $this->execute()->text);
    }

    public function testGameSuggestionsKeepOnlyTheGamesWithPulls(): void
    {
        $this->threeGames();

        $this->assertSame(['Game full_game' => 'full', 'Game checkin_game' => 'ci'], $this->suggest('game'));
        $this->assertSame(['Game full_game' => 'full'], $this->suggest('game', 'FULL'));
        $this->assertSame([], $this->suggest('game', 'plain'));
    }

    public function testProfileSuggestionsKeepOnlyTheProfilesWithPulls(): void
    {
        $this->threeGames();

        $this->assertSame(['Full Game · Nelly (700000001)' => 'fake:1', 'Checkin Game · Player2 (8000002)' => 'fake:2'], $this->suggest('profile'));
        // Narrowed by the game already chosen and by the input
        $this->assertSame(['Checkin Game · Player2 (8000002)' => 'fake:2'], $this->suggest('profile', '', ['game' => 'ci']));
        $this->assertSame(['Full Game · Nelly (700000001)' => 'fake:1'], $this->suggest('profile', 'nel'));
        $this->assertSame([], $this->suggest('profile', '', ['game' => 'plain']));
    }

    public function testBannerSuggestionsComeFromTheProfileOfTheOptions(): void
    {
        $this->threeGames();

        // Several profiles with pulls: no banner to suggest yet
        $this->assertSame([], $this->suggest('banner'));
        $this->assertSame(['Character Event' => 'char', 'Weapon Event' => 'weapon', 'Standard' => '200'], $this->suggest('banner', '', ['game' => 'full', 'all' => true]));
        // By name or key, ignoring case
        $this->assertSame(['Character Event' => 'char', 'Weapon Event' => 'weapon'], $this->suggest('banner', ' EVENT ', ['profile' => 'fake:2']));
        $this->assertSame(['Standard' => '200'], $this->suggest('banner', '20', ['game' => 'full']));
        $this->assertSame([], $this->suggest('banner', 'mystery', ['game' => 'full']));
        // A profile without pulls, or not found
        $this->assertSame([], $this->suggest('banner', '', ['profile' => 'fake:3']));
        $this->assertSame([], $this->suggest('banner', '', ['profile' => 'fake:9']));

        // The banners of the builder of the profile
        $both = GamesChatWorld::user(43, [GamesChatWorld::ROLE_GAMES, GamesChatWorld::ROLE_FAKE, GamesChatWorld::ROLE_OTHER]);
        $this->world->profile($both, 4);
        $this->world->otherProfile($both, 5);
        $this->assertSame(['Collab Hunt' => 'collab', 'Basic Hunt' => 'basic'], $this->suggest('banner', '', ['profile' => 'other:5'], $both));
        $this->assertSame(['Character Event' => 'char', 'Weapon Event' => 'weapon', 'Standard' => '200'], $this->suggest('banner', '', ['game' => 'full'], $both));
    }

    public function testBannerSuggestionsAreCappedAndTruncated(): void
    {
        $this->world->profile($this->user, 1);
        $this->builder->banners = [['key' => 'long', 'name' => str_repeat('L', 150)]];
        for ($i = 1; $i <= 30; ++$i) {
            $this->builder->banners[] = ['key' => 'b'.$i, 'name' => 'Banner '.$i];
        }

        $choices = $this->command->autocomplete(new ChatUser($this->user, ChatPlatform::Discord, 'd42'), null, 'banner', '', []);

        $this->assertCount(ChatProfileSelector::MAX_CHOICES, $choices);
        $this->assertSame(str_repeat('L', ChatProfileSelector::MAX_CHOICE_NAME - 1).'…', $choices[0]->name);
        $this->assertSame('long', $choices[0]->value);
        $this->assertSame('b24', $choices[24]->value);
    }

    public function testOtherOptionsHaveNoSuggestions(): void
    {
        $this->world->profile($this->user, 1);

        $this->assertSame([], $this->suggest('all'));
        $this->assertSame([], $this->suggest('player'));
    }

    private function createRenderer(): PullCardRenderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../../src/GamesBundle/templates', 'Games');

        // No icon on the cards of these tests
        $inliner = new class extends PullCardIconInliner {
            public function __construct()
            {
            }

            public function dataUri(string $iconPath): ?string
            {
                return null;
            }
        };

        $nodeServices = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($body);
            $this->assertIsInt($body['scale']);
            $this->scales[] = $body['scale'];
            if ($this->nodeServicesStatus !== 200) {
                return new MockResponse('Render failed', ['http_code' => $this->nodeServicesStatus]);
            }

            $image = new \Imagick();
            $image->newImage(self::PNG_WIDTH, self::PNG_HEIGHT, new \ImagickPixel('#1e293b'));
            $image->setImageFormat('png');
            $png = $image->getImagesBlob();
            $image->clear();

            return new MockResponse($png, ['http_code' => 200]);
        }, 'http://127.0.0.1:3003');

        return new PullCardRenderer(new Environment($loader, ['strict_variables' => true]), $inliner, $nodeServices, new AsciiSlugger());
    }
}

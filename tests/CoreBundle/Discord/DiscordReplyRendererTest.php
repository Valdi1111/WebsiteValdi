<?php

namespace App\Tests\CoreBundle\Discord;

use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatActionStyle;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\CoreBundle\Service\Chat\Discord\DiscordReplyRenderer;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * ChatReply → Discord message payload: the keys always present (an edit must clear what it does not repeat), embeds
 * through the notifier transformer and the limiter, buttons within the limits of Discord.
 */
class DiscordReplyRendererTest extends TestCase
{
    private BufferingLogger $logger;
    private DiscordReplyRenderer $renderer;

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
        $this->renderer = new DiscordReplyRenderer(new DiscordEmbedTransformer(), new DiscordEmbedLimiter(), new ChatActionCodec(), $this->logger);
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    private static function action(string $label, string ...$args): ChatAction
    {
        return new ChatAction($label, new ChatActionRef('page', $args));
    }

    /**
     * @param list<list<ChatAction>> $rows
     * @return list<list<string>> custom_id of the rendered buttons, by row
     */
    private function renderedCustomIds(array $rows): array
    {
        $components = $this->renderer->render(new ChatReply('Text', actionRows: $rows))['components'];

        return array_map(static fn (array $row): array => array_column($row['components'], 'custom_id'), $components);
    }

    public function testTextOnlyHasEveryKeySoThatAnEditClearsTheOldEmbedsAndButtons(): void
    {
        $this->assertSame(
            ['content' => 'Hello', 'embeds' => [], 'components' => [], 'allowed_mentions' => ['parse' => []]],
            $this->renderer->render(ChatReply::text('Hello')),
        );
    }

    public function testEmbedsGoThroughTheNotifierTransformer(): void
    {
        $embed = new UniversalEmbed()
            ->setTitle('Stamina')
            ->setDescription('Honkai: Star Rail')
            ->setColor(0x5865F2)
            ->setFooterText('Valdi')
            ->addField('Trailblaze Power', '180/240', '🔋', true);

        $payload = $this->renderer->render(ChatReply::embeds([$embed]));

        $this->assertSame('', $payload['content']);
        $this->assertSame([[
            'title' => 'Stamina',
            'description' => 'Honkai: Star Rail',
            'color' => 0x5865F2,
            'footer' => ['text' => 'Valdi'],
            'fields' => [['name' => 'Trailblaze Power', 'value' => "🔋\u{00A0}\u{00A0}180/240", 'inline' => true]],
        ]], $payload['embeds']);
        $this->assertSame(['parse' => []], $payload['allowed_mentions']);
    }

    public function testEmbedsAreLimited(): void
    {
        $embeds = array_map(static fn (int $i): UniversalEmbed => new UniversalEmbed()->setTitle(str_repeat('t', 300)), range(1, 12));

        $payload = $this->renderer->render(ChatReply::embeds($embeds));

        $this->assertCount(10, $payload['embeds']);
        $this->assertSame(256, mb_strlen($payload['embeds'][0]['title']));
    }

    public function testAnEmptyReplyBecomesTheGenericError(): void
    {
        $expected = ['content' => ChatReply::ERROR, 'embeds' => [], 'components' => [], 'allowed_mentions' => ['parse' => []]];

        $this->assertSame($expected, $this->renderer->render(new ChatReply()));
        $this->assertSame($expected, $this->renderer->render(ChatReply::text('  ')));
        // Buttons alone are not an answer
        $this->assertSame($expected, $this->renderer->render(new ChatReply(actionRows: [[self::action('Next', '2')]])));
    }

    public function testThePageGoesUnderTheTextOnlyWhenThereAreSeveral(): void
    {
        $embeds = [new UniversalEmbed()->setTitle('Stamina')];

        $this->assertSame('Page 2/3', $this->renderer->render(new ChatReply(null, $embeds, [], 2, 3))['content']);
        $this->assertSame("Your profiles\nPage 1/2", $this->renderer->render(new ChatReply('Your profiles', $embeds, [], 1, 2))['content']);
        $this->assertSame('Your profiles', $this->renderer->render(new ChatReply('Your profiles', $embeds, [], 1, 1))['content']);
        $this->assertSame('', $this->renderer->render(new ChatReply(null, $embeds))['content']);
        // The page alone is not an answer
        $this->assertSame(ChatReply::ERROR, $this->renderer->render(new ChatReply(null, [], [], 2, 3))['content']);
    }

    public function testContentIsCutToTwoThousandCharacters(): void
    {
        $content = $this->renderer->render(ChatReply::text(str_repeat('é', 2500)))['content'];

        $this->assertSame(2000, mb_strlen($content));
        $this->assertStringEndsWith('…', $content);
    }

    public function testButtonsHaveTheirStyleEmojiAndEncodedAction(): void
    {
        $reply = new ChatReply('Page 1/3', actionRows: [[
            new ChatAction('Previous', new ChatActionRef('page', ['stamina', '0']), '◀', ChatActionStyle::Primary),
            new ChatAction('Refresh', new ChatActionRef('refresh', ['hsr', '12']), '🔄'),
            new ChatAction('Enable', new ChatActionRef('enable', ['1']), style: ChatActionStyle::Success),
            new ChatAction('Disable', new ChatActionRef('disable', ['1']), style: ChatActionStyle::Danger),
            new ChatAction(str_repeat('L', 100), new ChatActionRef('long')),
        ]]);

        $this->assertSame([[
            'type' => 1,
            'components' => [
                ['type' => 2, 'style' => 1, 'custom_id' => 'page:stamina:0', 'label' => 'Previous', 'emoji' => ['name' => '◀']],
                ['type' => 2, 'style' => 2, 'custom_id' => 'refresh:hsr:12', 'label' => 'Refresh', 'emoji' => ['name' => '🔄']],
                ['type' => 2, 'style' => 3, 'custom_id' => 'enable:1', 'label' => 'Enable'],
                ['type' => 2, 'style' => 4, 'custom_id' => 'disable:1', 'label' => 'Disable'],
                ['type' => 2, 'style' => 2, 'custom_id' => 'long', 'label' => str_repeat('L', 79).'…'],
            ],
        ]], $this->renderer->render($reply)['components']);
    }

    public function testAtMostFiveRowsOfFiveButtons(): void
    {
        $rows = array_map(
            static fn (int $row): array => array_map(static fn (int $i): ChatAction => self::action('B', (string) $row, (string) $i), range(1, 6)),
            range(1, 6),
        );

        $customIds = $this->renderedCustomIds($rows);

        $this->assertCount(5, $customIds);
        $this->assertSame(['page:1:1', 'page:1:2', 'page:1:3', 'page:1:4', 'page:1:5'], $customIds[0]);
        $this->assertSame('page:5:5', $customIds[4][4]);
    }

    public function testARepeatedCustomIdIsDroppedAndARowLeftEmptyDoesNotCount(): void
    {
        $customIds = $this->renderedCustomIds([
            [self::action('A', '1'), self::action('A again', '1'), self::action('B', '2')],
            [self::action('B again', '2')],
            [],
            [self::action('C', '3')],
            [self::action('D', '4')],
            [self::action('E', '5')],
            [self::action('F', '6')],
            [self::action('G', '7')],
        ]);

        $this->assertSame([['page:1', 'page:2'], ['page:3'], ['page:4'], ['page:5'], ['page:6']], $customIds);
    }

    public function testAButtonTheCodecRefusesIsDroppedAndLogged(): void
    {
        $customIds = $this->renderedCustomIds([[
            self::action('Too long', str_repeat('x', 100)),
            new ChatAction('Bad', new ChatActionRef('a:b')),
            new ChatAction(' ', new ChatActionRef('blank')),
            self::action('Kept', '1'),
        ]]);

        $this->assertSame([['page:1']], $customIds);
        $this->assertCount(2, $this->logger->cleanLogs());
    }

    public function testTextFallbackKeepsTheTextOnly(): void
    {
        $embed = new UniversalEmbed()->setTitle('Stamina');

        $this->assertSame(
            ['content' => 'Your stamina', 'embeds' => [], 'components' => [], 'allowed_mentions' => ['parse' => []]],
            $this->renderer->renderText(ChatReply::embeds([$embed], [[self::action('Next', '2')]], 'Your stamina')),
        );
        $this->assertSame(ChatReply::ERROR, $this->renderer->renderText(ChatReply::embeds([$embed]))['content']);
        $this->assertSame(2000, mb_strlen($this->renderer->renderText(ChatReply::text(str_repeat('a', 3000)))['content']));
    }
}

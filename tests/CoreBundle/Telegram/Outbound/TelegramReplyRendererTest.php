<?php

namespace App\Tests\CoreBundle\Telegram\Outbound;

use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatImage;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\Telegram\TelegramReplyRenderer;
use App\CoreBundle\Service\Notification\TelegramHtmlRenderer;
use App\CoreBundle\Service\Notification\TelegramParsedown;
use App\GamesBundle\Presenter\EndgamesPresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * ChatReply → sendMessage / editMessageText parameters: the reply's text escaped, the embeds through the notifier's
 * HTML renderer, the keyboard always present, and a text Telegram never refuses for its length. The caption of an answer
 * with an image: its text alone, within the 1024 characters of a caption.
 */
class TelegramReplyRendererTest extends TestCase
{
    private BufferingLogger $logger;
    private TelegramReplyRenderer $renderer;

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
        $this->renderer = new TelegramReplyRenderer(new TelegramHtmlRenderer(new TelegramParsedown()), new ChatActionCodec(), $this->logger);
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    private static function action(string $label, ?string $emoji = null, string ...$args): ChatAction
    {
        return new ChatAction($label, new ChatActionRef('page', $args), $emoji);
    }

    private static function staminaEmbed(): UniversalEmbed
    {
        return new UniversalEmbed()
            ->setTitle('Stamina <HSR>')
            ->setDescription('**Honkai** & co')
            ->setThumbnailUrl('https://example.test/icon.png?size=64&v=2')
            ->setColor(0x5865F2)
            ->setFooterText('Valdi')
            ->addField('Trailblaze Power', '180/240', '🔋', true)
            // The filler of a Discord row: skipped on Telegram
            ->addField(EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, inline: true)
            ->addField('Full in', '3h', '🕒', true);
    }

    /**
     * Whether every "&" starts an entity Telegram knows and every tag is closed: what its parser checks.
     */
    private static function assertWellFormed(string $html): void
    {
        self::assertSame(0, preg_match('/&(?!(?:lt|gt|amp|quot|#\d+);)/', $html), 'A broken entity in: '.$html);
        $open = [];
        preg_match_all('#<(/?)([a-z-]+)[^>]*>#', $html, $tags, \PREG_SET_ORDER);
        foreach ($tags as [, $closing, $name]) {
            if ($closing === '') {
                $open[] = $name;
            } else {
                self::assertSame($name, array_pop($open), 'Tags out of order in: '.$html);
            }
        }
        self::assertSame([], $open, 'Unclosed tags');
        self::assertSame(0, preg_match('/<[^>]*$/', $html), 'A cut tag');
    }

    private static function image(): ChatImage
    {
        return new ChatImage('pulls.png', 'image/png', "\x89PNG", 2400, 1880);
    }

    private static function utf16(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function testTheTextIsEscapedAndEveryKeyIsSent(): void
    {
        $this->assertSame([
            'text' => 'Use &lt;b&gt;/stamina zzz&lt;/b&gt; &amp; "go"',
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            // Always there, empty: an edit would keep the old buttons otherwise
            'reply_markup' => ['inline_keyboard' => []],
        ], $this->renderer->render(ChatReply::text('Use <b>/stamina zzz</b> & "go"')));
    }

    public function testEmbedsGoThroughTheNotifierRendererOneFieldPerLine(): void
    {
        $second = new UniversalEmbed()->setTitle('Daily');

        $text = $this->renderer->render(ChatReply::embeds([self::staminaEmbed(), $second], [], 'Hi <you>'))['text'];

        $this->assertSame(
            "Hi &lt;you&gt;\n\n"
            .'<blockquote><a href="https://example.test/icon.png?size=64&amp;v=2">&#8205;</a><b>Stamina &lt;HSR&gt;</b>'."\n\n"
            ."<b>Honkai</b> &amp; co\n\n"
            ."<b>🔋 Trailblaze Power:</b> 180/240\n"
            ."<b>🕒 Full in:</b> 3h\n\n"
            ."<i>Valdi</i></blockquote>\n\n"
            .'<blockquote><b>Daily</b></blockquote>',
            $text,
        );
        $this->assertSame(new TelegramHtmlRenderer(new TelegramParsedown())->render(self::staminaEmbed(), TelegramReplyRenderer::INLINE_LAYOUT), $this->renderer->renderEmbed(self::staminaEmbed()));
        self::assertWellFormed($text);
    }

    public function testThePageGoesUnderTheEmbeds(): void
    {
        $embed = new UniversalEmbed()->setTitle('Stamina');

        $this->assertSame("<blockquote><b>Stamina</b></blockquote>\n\nPage 2/3", $this->renderer->render(new ChatReply(null, [$embed], [], 2, 3))['text']);
        $this->assertSame('<blockquote><b>Stamina</b></blockquote>', $this->renderer->render(new ChatReply(null, [$embed]))['text']);
    }

    public function testButtonsBecomeAnInlineKeyboard(): void
    {
        $rows = [
            [self::action('Previous', '◀️', '1'), self::action('Next', '▶️', '3'), new ChatAction('Refresh', new ChatActionRef('refresh', ['hsr', '12']), '🔄')],
            // Nothing to show: dropped, and so is the row
            [new ChatAction(' ', new ChatActionRef('empty'))],
            [self::action('Only a label', null, '9')],
        ];

        $this->assertSame([
            [
                ['text' => '◀️ Previous', 'callback_data' => 'page:1'],
                ['text' => '▶️ Next', 'callback_data' => 'page:3'],
                ['text' => '🔄 Refresh', 'callback_data' => 'refresh:hsr:12'],
            ],
            [['text' => 'Only a label', 'callback_data' => 'page:9']],
        ], $this->renderer->render(new ChatReply('Text', actionRows: $rows))['reply_markup']['inline_keyboard']);
    }

    public function testRowsWrapAtEightButtonsAndDuplicatesAreDropped(): void
    {
        $row = array_map(static fn (int $i): ChatAction => self::action('P'.$i, null, (string) $i), range(1, 10));
        $row[] = self::action('Again', null, '1');

        $keyboard = $this->renderer->render(new ChatReply('Text', actionRows: [$row, [self::action('Twice', null, '2'), self::action('Last', null, '11')]]))['reply_markup']['inline_keyboard'];

        $this->assertSame([8, 2, 1], array_map(count(...), $keyboard));
        $this->assertSame(['page:9', 'page:10'], array_column($keyboard[1], 'callback_data'));
        $this->assertSame([['text' => 'Last', 'callback_data' => 'page:11']], $keyboard[2]);
    }

    public function testAButtonTheCodecRefusesIsDroppedAndLogged(): void
    {
        $rows = [[
            new ChatAction('Bad', new ChatActionRef('page', ['a:b'])),
            new ChatAction('Too long', new ChatActionRef('page', [str_repeat('x', 64)])),
            self::action('Good', null, '2'),
        ]];

        $keyboard = $this->renderer->render(new ChatReply('Text', actionRows: $rows))['reply_markup']['inline_keyboard'];

        $this->assertSame([[['text' => 'Good', 'callback_data' => 'page:2']]], $keyboard);
        $logs = $this->logger->cleanLogs();
        $this->assertCount(2, $logs);
        $this->assertSame(['error', 'Chat button dropped: {error}'], [$logs[0][0], $logs[0][1]]);
        $this->assertSame('page', $logs[0][2]['handler']);
    }

    public function testNothingToShowIsTheGenericError(): void
    {
        $error = [
            'text' => ChatReply::ERROR,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => []],
        ];

        $this->assertSame($error, $this->renderer->render(new ChatReply(' ', actionRows: [[self::action('Next', null, '2')]], page: 1, pageCount: 2)));
        // An embed with nothing but a color renders to nothing
        $this->assertSame($error, $this->renderer->render(ChatReply::embeds([new UniversalEmbed()->setColor(0xFF0000)])));
    }

    public function testATextOfExactly4096UnitsIsKept(): void
    {
        // Measured after the entity parsing, in UTF-16 code units: "&amp;" is one, an emoji beyond the BMP two
        $fits = str_repeat('&', 4000).str_repeat('a', 94).'🔋';
        $this->assertSame(4096, self::utf16($fits));

        $this->assertSame(htmlspecialchars($fits, \ENT_NOQUOTES), $this->renderer->render(ChatReply::text($fits))['text']);
    }

    public function testALongTextIsCutWithoutBreakingAnEntity(): void
    {
        $long = str_repeat('a<&🔋 ', 1500);

        $text = $this->renderer->render(ChatReply::text($long))['text'];

        self::assertWellFormed($text);
        $visible = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $this->assertLessThanOrEqual(TelegramReplyRenderer::MAX_TEXT, self::utf16($visible));
        $this->assertGreaterThan(TelegramReplyRenderer::MAX_TEXT - 8, self::utf16($visible));
        $this->assertStringEndsWith(TelegramReplyRenderer::ELLIPSIS, $visible);
        $this->assertStringStartsWith(mb_substr($visible, 0, -1), $long);
    }

    public function testAnEmbedTooLargeOnItsOwnIsCutAsPlainTextUnderThePage(): void
    {
        $huge = new UniversalEmbed()->setTitle('Endgames')->setThumbnailUrl('https://example.test/icon.png');
        for ($i = 0; $i < 200; $i++) {
            $huge->addField('Mode <'.$i.'>', '**36/36** & 🔋 done', '⚔️');
        }

        $text = $this->renderer->render(new ChatReply(null, [$huge], [[self::action('Next', '▶️', '3')]], 2, 3))['text'];

        self::assertWellFormed($text);
        $this->assertStringNotContainsString('<b>', $text, 'Cut as plain text');
        $this->assertStringEndsWith(TelegramReplyRenderer::ELLIPSIS."\n\nPage 2/3", $text);
        $this->assertLessThanOrEqual(TelegramReplyRenderer::MAX_TEXT, TelegramReplyRenderer::visibleLength($text));
        $this->assertGreaterThan(TelegramReplyRenderer::MAX_TEXT - 50, TelegramReplyRenderer::visibleLength($text));
        $this->assertStringContainsString('⚔️ Mode &lt;0&gt;: 36/36 &amp; 🔋 done', $text);
    }

    public function testVisibleLengthIsWhatTelegramCounts(): void
    {
        $this->assertSame(0, TelegramReplyRenderer::visibleLength(''));
        $this->assertSame(5, TelegramReplyRenderer::visibleLength('<b>a&lt;</b>&amp;<i>&quot;</i>&#039;'));
        // Thumbnail link: a zero-width joiner
        $this->assertSame(2, TelegramReplyRenderer::visibleLength('<a href="https://example.test/?a=1&amp;b=2">&#8205;</a>x'));
        $this->assertSame(2, TelegramReplyRenderer::visibleLength('🔋'));
        $this->assertSame(2, TelegramReplyRenderer::visibleLength('⚔️'));
        // A numeric entity beyond the BMP: 2 units
        $this->assertSame(2, TelegramReplyRenderer::visibleLength('&#x1F50B;'));
        // Entities Telegram does not decode are shown as written
        $this->assertSame(6, TelegramReplyRenderer::visibleLength('&nbsp;'));
        $this->assertSame(6, TelegramReplyRenderer::visibleLength('&apos;'));
    }

    public function testThePlainRenderHasNoMarkupNorParseMode(): void
    {
        $reply = new ChatReply('Hi <you> & "me"', [self::staminaEmbed()], [[self::action('Next', '▶️', '3')]], 2, 3);

        $plain = $this->renderer->renderPlain($reply);

        $this->assertSame([
            // Shown as it is: not escaped
            'text' => "Hi <you> & \"me\"\n\n"
                ."\u{200D}Stamina <HSR>\n\n"
                ."Honkai & co\n\n"
                ."🔋 Trailblaze Power: 180/240\n"
                ."🕒 Full in: 3h\n\n"
                ."Valdi\n\n"
                .'Page 2/3',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => [[['text' => '▶️ Next', 'callback_data' => 'page:3']]]],
        ], $plain);
        $this->assertArrayNotHasKey('parse_mode', $plain);
        $this->assertSame(['text' => ChatReply::ERROR, 'link_preview_options' => ['is_disabled' => true], 'reply_markup' => ['inline_keyboard' => []]], $this->renderer->renderPlain(new ChatReply()));
    }

    public function testThePlainRenderIsCutToo(): void
    {
        $text = $this->renderer->renderPlain(new ChatReply(str_repeat('🔋<', 3000), [], [], 1, 2))['text'];

        $this->assertLessThanOrEqual(TelegramReplyRenderer::MAX_TEXT, self::utf16($text));
        $this->assertStringEndsWith(TelegramReplyRenderer::ELLIPSIS."\n\nPage 1/2", $text);
        $this->assertStringStartsWith('🔋<🔋<', $text);
    }

    public function testTheCaptionIsTheTextAloneEscaped(): void
    {
        $this->assertSame(
            ['caption' => 'Your &lt;b&gt;pulls&lt;/b&gt; &amp; "luck"', 'parse_mode' => 'HTML'],
            $this->renderer->renderCaption(ChatReply::image(self::image(), '  Your <b>pulls</b> & "luck"'."\n")),
        );
        // The embeds and the page are for text answers
        $reply = new ChatReply('Your pulls', [self::staminaEmbed()], [[self::action('Next', '▶️', '3')]], 2, 3, self::image());
        $this->assertSame(['caption' => 'Your pulls', 'parse_mode' => 'HTML'], $this->renderer->renderCaption($reply));
    }

    public function testThePlainCaptionIsNotEscapedAndHasNoParseMode(): void
    {
        $this->assertSame(
            ['caption' => 'Your <b>pulls</b> & "luck"'],
            $this->renderer->renderPlainCaption(ChatReply::image(self::image(), ' Your <b>pulls</b> & "luck" ')),
        );
    }

    public function testNoTextNoCaption(): void
    {
        foreach ([ChatReply::image(self::image()), ChatReply::image(self::image(), " \n ")] as $reply) {
            $this->assertSame([], $this->renderer->renderCaption($reply));
            $this->assertSame([], $this->renderer->renderPlainCaption($reply));
        }
    }

    public function testACaptionOfExactly1024UnitsIsKept(): void
    {
        // Measured before the escaping, in UTF-16 code units: "&" is one, an emoji beyond the BMP two
        $fits = str_repeat('&', 1000).str_repeat('a', 22).'🔋';
        $this->assertSame(TelegramReplyRenderer::MAX_CAPTION, self::utf16($fits));

        $this->assertSame(htmlspecialchars($fits, \ENT_NOQUOTES), $this->renderer->renderCaption(ChatReply::image(self::image(), $fits))['caption']);
        $this->assertSame($fits, $this->renderer->renderPlainCaption(ChatReply::image(self::image(), $fits))['caption']);
    }

    public function testALongCaptionIsCutWithTheEllipsis(): void
    {
        $long = str_repeat('a<&🔋 ', 300);

        $caption = $this->renderer->renderCaption(ChatReply::image(self::image(), $long))['caption'];

        self::assertWellFormed($caption);
        $visible = html_entity_decode($caption, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $this->assertLessThanOrEqual(TelegramReplyRenderer::MAX_CAPTION, self::utf16($visible));
        $this->assertGreaterThan(TelegramReplyRenderer::MAX_CAPTION - 8, self::utf16($visible));
        $this->assertStringEndsWith(TelegramReplyRenderer::ELLIPSIS, $visible);
        $this->assertStringStartsWith(mb_substr($visible, 0, -1), $long);
        $this->assertStringStartsWith('a&lt;&amp;🔋 a&lt;', $caption);

        $plain = $this->renderer->renderPlainCaption(ChatReply::image(self::image(), $long))['caption'];
        $this->assertSame($visible, $plain, 'The same text, not escaped');
    }
}

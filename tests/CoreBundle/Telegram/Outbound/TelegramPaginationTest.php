<?php

namespace App\Tests\CoreBundle\Telegram\Outbound;

use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatPaginator;
use App\CoreBundle\Service\Chat\Telegram\TelegramReplyRenderer;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use App\CoreBundle\Service\Notification\TelegramHtmlRenderer;
use App\CoreBundle\Service\Notification\TelegramParsedown;
use App\GamesBundle\Presenter\EndgamesPresenter;
use PHPUnit\Framework\TestCase;

/**
 * Telegram pages measured on the text Telegram counts (TelegramReplyRenderer: tags removed, entities decoded, UTF-16
 * code units, separators and page line), so that the renderer never has to cut a page.
 */
class TelegramPaginationTest extends TestCase
{
    /** What the embeds of a page may take, each with its separator */
    private const int BUDGET = TelegramReplyRenderer::MAX_TEXT - ChatPaginator::TELEGRAM_PAGE_LINE;

    private TelegramReplyRenderer $renderer;
    private ChatPaginator $paginator;

    protected function setUp(): void
    {
        $this->renderer = new TelegramReplyRenderer(new TelegramHtmlRenderer(new TelegramParsedown()), new ChatActionCodec());
        $this->paginator = new ChatPaginator(new DiscordEmbedTransformer(), $this->renderer);
    }

    /**
     * @param list<UniversalEmbed> $embeds
     * @return list<int> embeds per page
     */
    private function pageSizes(array $embeds): array
    {
        $first = $this->paginator->paginate($embeds, ChatPlatform::Telegram);
        $sizes = [];
        for ($page = 1; $page <= $first->pageCount; $page++) {
            $sizes[] = count($this->paginator->paginate($embeds, ChatPlatform::Telegram, $page)->embeds);
        }

        return $sizes;
    }

    private static function titled(int $length, string $letter = 'a'): UniversalEmbed
    {
        return new UniversalEmbed()->setTitle(str_repeat($letter, $length));
    }

    public function testTheDefaultMeasureIsTheOneOfTheRenderer(): void
    {
        $paginator = new ChatPaginator(new DiscordEmbedTransformer());
        $embeds = [self::titled(2039), self::titled(2040), self::titled(5)];

        $this->assertSame(
            count($this->paginator->paginate($embeds, ChatPlatform::Telegram)->embeds),
            count($paginator->paginate($embeds, ChatPlatform::Telegram)->embeds),
        );
    }

    public function testAPageHoldsTheBudgetExactly(): void
    {
        // A title alone is shown as it is: its length, plus its separator
        $this->assertSame(2039, TelegramReplyRenderer::visibleLength($this->renderer->renderEmbed(self::titled(2039))));
        $this->assertSame([2, 1], $this->pageSizes([self::titled(2039), self::titled(2039), self::titled(5)]));
        $this->assertSame([1, 2], $this->pageSizes([self::titled(2039), self::titled(2040), self::titled(5)]));

        // Full, with the page line under it, and still whole
        $page = $this->paginator->paginate([self::titled(2039), self::titled(2039), self::titled(5)], ChatPlatform::Telegram);
        $text = $this->renderer->render(new ChatReply(null, $page->embeds, [], $page->page, $page->pageCount))['text'];
        $this->assertSame(2039 + 2 + 2039 + strlen("\n\nPage 1/2"), TelegramReplyRenderer::visibleLength($text));
        $this->assertStringNotContainsString(TelegramReplyRenderer::ELLIPSIS, $text);
    }

    public function testEntitiesCountAsTheCharacterTheyShow(): void
    {
        // About 10 000 bytes of HTML each ("&amp;"), 2000 characters on the screen: both fit one page
        $ampersands = new UniversalEmbed()->setTitle('&')->setDescription(str_repeat('&', 2000));
        $this->assertGreaterThan(2 * TelegramReplyRenderer::MAX_TEXT, strlen($this->renderer->renderEmbed($ampersands)));

        $this->assertSame([2], $this->pageSizes([$ampersands, clone $ampersands]));
    }

    public function testEmojiBeyondTheBmpCountTwice(): void
    {
        // 1100 code points, 2200 UTF-16 code units: two would fit if counted as code points, they do not for Telegram
        $batteries = new UniversalEmbed()->setTitle('B')->setDescription(str_repeat('🔋', 1100));

        $this->assertSame([1, 1], $this->pageSizes([$batteries, clone $batteries]));
    }

    public function testAnEmbedTooLargeOnItsOwnFillsAPageAloneAndIsCut(): void
    {
        $huge = new UniversalEmbed()->setTitle('Huge');
        for ($i = 0; $i < 200; $i++) {
            $huge->addField('Field '.$i, '**30/30** & 🔋', '⚔️');
        }

        $this->assertSame([1, 1, 1], $this->pageSizes([self::titled(10), $huge, self::titled(10)]));

        $text = $this->renderer->render(new ChatReply(null, [$huge], [], 2, 3))['text'];
        $this->assertLessThanOrEqual(TelegramReplyRenderer::MAX_TEXT, TelegramReplyRenderer::visibleLength($text));
        $this->assertStringEndsWith(TelegramReplyRenderer::ELLIPSIS."\n\nPage 2/3", $text);
    }

    /**
     * Whatever the embeds, as long as each fits a page on its own, every page goes to Telegram exactly as rendered:
     * nothing cut, and within its 4096 characters.
     */
    public function testTheRendererNeverCutsAPage(): void
    {
        // A page at the budget exactly, then whatever comes
        $embeds = [self::titled(2039, 'p'), self::titled(2039, 'q')];
        mt_srand(20261127);
        while (count($embeds) < 150) {
            $embed = self::randomEmbed();
            // One embed above a page is cut by design (testAnEmbedTooLargeOnItsOwnFillsAPageAloneAndIsCut)
            if (TelegramReplyRenderer::visibleLength($this->renderer->renderEmbed($embed)) + 2 <= self::BUDGET) {
                $embeds[] = $embed;
            }
        }

        $pageCount = $this->paginator->paginate($embeds, ChatPlatform::Telegram)->pageCount;
        $seen = [];
        $lengths = [];
        for ($number = 1; $number <= $pageCount; $number++) {
            $page = $this->paginator->paginate($embeds, ChatPlatform::Telegram, $number);

            $text = $this->renderer->render(new ChatReply(null, $page->embeds, [], $page->page, $page->pageCount))['text'];

            $whole = implode("\n\n", array_map($this->renderer->renderEmbed(...), $page->embeds))."\n\n".sprintf('Page %d/%d', $number, $pageCount);
            $this->assertSame($whole, $text, sprintf('page %d of %d', $number, $pageCount));
            $lengths[] = TelegramReplyRenderer::visibleLength($text);
            array_push($seen, ...$page->embeds);
        }

        $this->assertSame($embeds, $seen, 'every embed once, in order');
        $this->assertLessThanOrEqual(TelegramReplyRenderer::MAX_TEXT, max($lengths));
        $this->assertSame(2039 + 2 + 2039 + strlen("\n\nPage 1/".$pageCount), $lengths[0]);
        $this->assertGreaterThan(20, $pageCount);
        // Filled close to the limit, not merely below it
        $this->assertGreaterThan(5, count(array_filter($lengths, static fn (int $length): bool => $length > TelegramReplyRenderer::MAX_TEXT - 600)));
    }

    /**
     * Texts with what makes the HTML longer than what Telegram shows (entities, Markdown, links, spoilers) and what
     * makes UTF-16 longer than the code points (emoji beyond the BMP), in every part of an embed.
     */
    private static function randomEmbed(): UniversalEmbed
    {
        $tokens = ['stamina', 'Trailblaze', '180/240', '&', '<b>', 'a<b', '"quoted"', "it's", '🔋', '⚔️', '🕒', 'città', '**bold**', '_italic_', '||spoiler||', '`code`', '[site](https://example.test/?a=1&b=2)', 'https://example.test/x?y=1&z=2', '~~gone~~', '&nbsp;', '&copy;', '&#128267;'];
        $text = static function (int $words) use ($tokens): string {
            $picked = [];
            for ($i = 0; $i < max(1, $words); $i++) {
                $picked[] = $tokens[mt_rand(0, count($tokens) - 1)];
            }

            return implode(' ', $picked);
        };

        $embed = new UniversalEmbed()->setTitle($text(mt_rand(1, 6)));
        if (mt_rand(0, 2) === 0) {
            $embed->setDescription($text(mt_rand(0, 1) === 0 ? 20 : 300));
        }
        if (mt_rand(0, 3) === 0) {
            $embed->setThumbnailUrl('https://example.test/icon.png?size=64&v='.mt_rand(1, 9));
        }
        for ($field = mt_rand(0, 15); $field > 0; $field--) {
            if (mt_rand(0, 5) === 0) {
                // The filler of a Discord row, skipped on Telegram
                $embed->addField(EndgamesPresenter::FILLER, EndgamesPresenter::FILLER, inline: true);
                continue;
            }
            $value = mt_rand(0, 6) === 0 ? $text(10)."\n".$text(10) : $text(mt_rand(1, 8));
            $embed->addField($text(mt_rand(1, 3)), $value, icon: [null, '🔋', '⚔️', '🕒'][mt_rand(0, 3)], inline: mt_rand(0, 1) === 1);
        }
        if (mt_rand(0, 1) === 0) {
            $embed->setFooterText($text(mt_rand(1, 5)));
        }

        return $embed;
    }
}

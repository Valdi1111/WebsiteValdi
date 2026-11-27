<?php

namespace App\Tests\CoreBundle\Chat;

use App\CoreBundle\Model\Chat\ChatPage;
use App\CoreBundle\Model\Chat\ChatPlatform;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatActionCodec;
use App\CoreBundle\Service\Chat\ChatPaginator;
use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use App\CoreBundle\Service\Chat\Discord\DiscordReplyRenderer;
use App\CoreBundle\Service\Notification\DiscordEmbedTransformer;
use PHPUnit\Framework\TestCase;

/**
 * Pages of embeds within one Discord message (10 embeds, 6000 characters counted as Discord counts them), so that
 * DiscordEmbedLimiter, the safety net of the renderer, never cuts a page.
 */
class ChatPaginatorTest extends TestCase
{
    private ChatPaginator $paginator;

    protected function setUp(): void
    {
        $this->paginator = new ChatPaginator(new DiscordEmbedTransformer());
    }

    /**
     * An embed of exactly $characters characters as Discord counts them (title, then description).
     */
    private static function embed(int $characters, string $letter = 'a'): UniversalEmbed
    {
        $title = min($characters, DiscordEmbedLimiter::MAX_TITLE);

        return new UniversalEmbed()
            ->setTitle(str_repeat($letter, $title))
            ->setDescription($characters > $title ? str_repeat($letter, $characters - $title) : null);
    }

    /**
     * @param list<UniversalEmbed> $embeds
     * @return list<int> embeds per page
     */
    private function pageSizes(array $embeds, ChatPlatform $platform = ChatPlatform::Discord): array
    {
        $first = $this->paginator->paginate($embeds, $platform);
        $sizes = [];
        for ($page = 1; $page <= $first->pageCount; $page++) {
            $sizes[] = count($this->paginator->paginate($embeds, $platform, $page)->embeds);
        }

        return $sizes;
    }

    public function testAtMostTenEmbedsPerPage(): void
    {
        $embeds = array_map(static fn (int $i): UniversalEmbed => self::embed(10), range(1, 25));

        $this->assertSame([10, 10, 5], $this->pageSizes($embeds));
        $page = $this->paginator->paginate($embeds, ChatPlatform::Discord, 2);
        $this->assertSame([2, 3], [$page->page, $page->pageCount]);
        $this->assertSame(array_slice($embeds, 10, 10), $page->embeds, 'in order, the same instances');
    }

    public function testAtMostSixThousandCharactersPerPage(): void
    {
        // 3 × 2000 = 6000 fits exactly; one character more does not
        $this->assertSame([3, 3, 1], $this->pageSizes(array_map(static fn (int $i): UniversalEmbed => self::embed(2000), range(1, 7))));
        $this->assertSame([2, 2, 2, 1], $this->pageSizes(array_map(static fn (int $i): UniversalEmbed => self::embed(2001), range(1, 7))));
    }

    public function testCharactersAreCountedOnTheEmbedDiscordGets(): void
    {
        // The field icon goes before the value with two no-break spaces: 997 + 3 characters more
        $withIcon = new UniversalEmbed()->addField('N', str_repeat('v', 997), icon: '🔋');
        $this->assertSame([1, 1], $this->pageSizes([self::embed(5000), $withIcon]));

        $withoutIcon = new UniversalEmbed()->addField('N', str_repeat('v', 997));
        $this->assertSame([2], $this->pageSizes([self::embed(5000), $withoutIcon]));

        // The footer counts too, the color and the timestamp do not
        $footer = self::embed(990)->setFooterText(str_repeat('f', 10))->setColor(0x3498DB)->setTimestamp(new \DateTimeImmutable());
        $this->assertSame([2], $this->pageSizes([self::embed(5000), $footer]));
        $this->assertSame([1, 1], $this->pageSizes([self::embed(5001), $footer]));
    }

    public function testAnEmbedTooLargeOnItsOwnFillsAPageAlone(): void
    {
        $huge = self::embed(DiscordEmbedLimiter::MAX_TITLE + DiscordEmbedLimiter::MAX_DESCRIPTION);
        for ($i = 0; $i < 3; $i++) {
            $huge->addField('Field', str_repeat('x', DiscordEmbedLimiter::MAX_FIELD_VALUE));
        }

        $this->assertSame([1, 1, 1], $this->pageSizes([self::embed(10), $huge, self::embed(10)]));
        $this->assertSame([1], $this->pageSizes([$huge]));

        // The limiter cuts it to the 6000 characters of a message
        $page = $this->paginator->paginate([$huge], ChatPlatform::Discord);
        $rendered = self::renderer()->render(new ChatReply(null, $page->embeds))['embeds'];
        $this->assertSame(DiscordEmbedLimiter::MAX_TOTAL, DiscordEmbedLimiter::countText($rendered[0]));
    }

    public function testThePageIsClampedToThePagesThereAre(): void
    {
        $embeds = array_map(static fn (int $i): UniversalEmbed => self::embed(3000, chr(ord('a') + $i)), range(0, 4));

        $this->assertSame([1, 3], self::pageAndCount($this->paginator->paginate($embeds, ChatPlatform::Discord, 0)));
        $this->assertSame([1, 3], self::pageAndCount($this->paginator->paginate($embeds, ChatPlatform::Discord, -4)));
        $last = $this->paginator->paginate($embeds, ChatPlatform::Discord, 999);
        $this->assertSame([3, 3], self::pageAndCount($last));
        $this->assertSame([$embeds[4]], $last->embeds);
    }

    public function testNoEmbedsIsOneEmptyPage(): void
    {
        $page = $this->paginator->paginate([], ChatPlatform::Discord, 3);

        $this->assertSame([], $page->embeds);
        $this->assertSame([1, 1], self::pageAndCount($page));
    }

    public function testTelegramPagesHoldItsMessageLength(): void
    {
        $this->assertSame(4096, ChatPaginator::maxCharacters(ChatPlatform::Telegram));
        $this->assertSame([2, 1], $this->pageSizes([self::embed(2048), self::embed(2048), self::embed(1)], ChatPlatform::Telegram));
        $this->assertSame([3], $this->pageSizes([self::embed(2048), self::embed(2048), self::embed(1)], ChatPlatform::Cli));
    }

    /**
     * Whatever the embeds, as long as each respects the limits of its own texts, every page goes to Discord exactly as
     * built: the limiter has nothing to cut or drop.
     */
    public function testTheLimiterNeverCutsAPage(): void
    {
        // A page of exactly 6000 characters, then one of ten embeds, then whatever comes
        $embeds = [self::embed(2000, 'p'), self::embed(2000, 'q'), self::embed(2000, 'r')];
        array_push($embeds, ...array_map(static fn (int $i): UniversalEmbed => self::embed(50, 's'), range(1, 10)));
        mt_srand(20261127);
        for ($i = 0; $i < 120; $i++) {
            $embeds[] = self::randomEmbed();
        }

        $transformer = new DiscordEmbedTransformer();
        $renderer = self::renderer();
        $pageCount = $this->paginator->paginate($embeds, ChatPlatform::Discord)->pageCount;
        $seen = [];
        $sizes = [];
        for ($number = 1; $number <= $pageCount; $number++) {
            $page = $this->paginator->paginate($embeds, ChatPlatform::Discord, $number);
            $raw = array_map(static fn (UniversalEmbed $embed): array => $transformer->transform($embed)->toArray(), $page->embeds);

            $rendered = $renderer->render(new ChatReply(null, $page->embeds, [], $page->page, $page->pageCount));

            $this->assertSame($raw, $rendered['embeds'], sprintf('page %d of %d', $number, $pageCount));
            $this->assertSame(sprintf('Page %d/%d', $number, $pageCount), $rendered['content']);
            $sizes[] = array_sum(array_map(DiscordEmbedLimiter::countText(...), $raw));
            array_push($seen, ...$page->embeds);
        }

        $this->assertSame($embeds, $seen, 'every embed once, in order');
        $this->assertSame(DiscordEmbedLimiter::MAX_TOTAL, $sizes[0]);
        $this->assertCount(DiscordEmbedLimiter::MAX_EMBEDS, $this->paginator->paginate($embeds, ChatPlatform::Discord, 2)->embeds);
        $this->assertGreaterThan(5, $pageCount);
    }

    /**
     * Within the limits of each text of Discord, the icon of a field counted in its value.
     */
    private static function randomEmbed(): UniversalEmbed
    {
        $text = static fn (int $max): string => str_repeat(chr(mt_rand(ord('a'), ord('z'))), mt_rand(1, $max));
        $embed = new UniversalEmbed()->setTitle($text(DiscordEmbedLimiter::MAX_TITLE));
        if (mt_rand(0, 3) === 0) {
            $embed->setDescription($text(mt_rand(0, 1) === 0 ? 300 : 3000));
        }
        for ($field = mt_rand(0, 12); $field > 0; $field--) {
            $icon = mt_rand(0, 1) === 0 ? '⚔️' : null;
            // "⚔️" is two code points, then two no-break spaces
            $embed->addField($text(40), $text(mt_rand(0, 4) === 0 ? DiscordEmbedLimiter::MAX_FIELD_VALUE - 4 : 80), icon: $icon, inline: mt_rand(0, 1) === 1);
        }
        if (mt_rand(0, 1) === 0) {
            $embed->setFooterText($text(30));
        }

        return $embed;
    }

    private static function renderer(): DiscordReplyRenderer
    {
        return new DiscordReplyRenderer(new DiscordEmbedTransformer(), new DiscordEmbedLimiter(), new ChatActionCodec());
    }

    /**
     * @return array{int, int}
     */
    private static function pageAndCount(ChatPage $page): array
    {
        return [$page->page, $page->pageCount];
    }
}

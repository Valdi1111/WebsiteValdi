<?php

namespace App\Tests\CoreBundle\Discord;

use App\CoreBundle\Service\Chat\Discord\DiscordEmbedLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The limits of Discord on the embeds of one message: each text, the fields, the embeds, and the 6000 characters
 * shared by all of them.
 */
class DiscordEmbedLimiterTest extends TestCase
{
    private DiscordEmbedLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new DiscordEmbedLimiter();
    }

    public function testTruncateCutsToTheLimitWithAnEllipsisCountingCharactersNotBytes(): void
    {
        $this->assertSame('abc', DiscordEmbedLimiter::truncate('abc', 3));
        $this->assertSame('ab…', DiscordEmbedLimiter::truncate('abcd', 3));
        $this->assertSame('èè…', DiscordEmbedLimiter::truncate('èèèè', 3));
        $this->assertSame('…', DiscordEmbedLimiter::truncate('abcd', 1));
        $this->assertSame('', DiscordEmbedLimiter::truncate('abcd', 0));
    }

    public function testAnEmbedWithinTheLimitsIsUnchanged(): void
    {
        $embed = [
            'title' => 'Stamina',
            'description' => 'Honkai: Star Rail',
            'color' => 0x5865F2,
            'fields' => [['name' => 'Trailblaze Power', 'value' => '180/240', 'inline' => true]],
            'footer' => ['text' => 'Updated', 'icon_url' => 'https://valdi.test/icon.png'],
            'thumbnail' => ['url' => 'https://valdi.test/hsr.png'],
            'timestamp' => '2026-11-27T10:00:00+0000',
        ];

        $this->assertSame([$embed], $this->limiter->limit([$embed]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string|int>, int}>
     */
    public static function textLimits(): iterable
    {
        yield 'title' => [['title' => str_repeat('t', 300)], ['title'], 256];
        yield 'description' => [['description' => str_repeat('d', 5000)], ['description'], 4096];
        yield 'author' => [['author' => ['name' => str_repeat('a', 300), 'url' => 'https://valdi.test']], ['author', 'name'], 256];
        yield 'footer' => [['footer' => ['text' => str_repeat('f', 3000)]], ['footer', 'text'], 2048];
        yield 'field name' => [['fields' => [['name' => str_repeat('n', 300), 'value' => 'v']]], ['fields', 0, 'name'], 256];
        yield 'field value' => [['fields' => [['name' => 'n', 'value' => str_repeat('v', 2000)]]], ['fields', 0, 'value'], 1024];
    }

    /**
     * @param array<string, mixed> $embed
     * @param list<string|int>     $path
     */
    #[DataProvider('textLimits')]
    public function testEachTextIsCutToItsLimit(array $embed, array $path, int $limit): void
    {
        $value = $this->limiter->limit([$embed])[0];
        foreach ($path as $key) {
            $value = $value[$key];
        }

        $this->assertSame($limit, mb_strlen($value));
        $this->assertStringEndsWith(DiscordEmbedLimiter::ELLIPSIS, $value);
    }

    public function testFieldsBeyondTwentyFiveAreDropped(): void
    {
        $fields = array_map(static fn (int $i): array => ['name' => 'F'.$i, 'value' => 'v'], range(1, 30));

        $limited = $this->limiter->limit([['fields' => $fields]])[0]['fields'];

        $this->assertCount(25, $limited);
        $this->assertSame('F25', $limited[24]['name']);
    }

    public function testEmbedsBeyondTenAreDropped(): void
    {
        $embeds = array_map(static fn (int $i): array => ['title' => 'E'.$i], range(1, 12));

        $limited = $this->limiter->limit($embeds);

        $this->assertCount(10, $limited);
        $this->assertSame('E10', $limited[9]['title']);
    }

    public function testTheSixThousandCharactersAreSpentInReadingOrder(): void
    {
        $embeds = [
            ['title' => 'One', 'description' => str_repeat('a', 4096)],
            ['title' => 'Two', 'description' => str_repeat('b', 4096), 'footer' => ['text' => 'two']],
            ['title' => 'Three', 'description' => 'c'],
        ];

        $limited = $this->limiter->limit($embeds);

        // 3 + 4096 for the first, then the title and what is left of the description; no room for the rest
        $this->assertCount(2, $limited);
        $this->assertSame(str_repeat('a', 4096), $limited[0]['description']);
        $this->assertSame('Two', $limited[1]['title']);
        $this->assertSame(6000 - 3 - 4096 - 3, mb_strlen($limited[1]['description']));
        $this->assertStringEndsWith(DiscordEmbedLimiter::ELLIPSIS, $limited[1]['description']);
        $this->assertArrayNotHasKey('footer', $limited[1]);
        $this->assertSame(DiscordEmbedLimiter::MAX_TOTAL, array_sum(array_map(DiscordEmbedLimiter::countText(...), $limited)));
    }

    public function testAFieldIsKeptWithItsWholeNameOrDropped(): void
    {
        $embed = [
            'description' => str_repeat('d', 4096),
            'fields' => [
                ['name' => 'Field 1', 'value' => str_repeat('1', 1024)],
                ['name' => 'Field 2', 'value' => str_repeat('2', 1024)],
                ['name' => 'Field 3', 'value' => str_repeat('3', 1024)],
            ],
        ];

        $fields = $this->limiter->limit([$embed])[0]['fields'];

        // 6000 - 4096 = 1904: field 1 whole (1031), field 2 with its name and the rest of the value, field 3 dropped
        $this->assertCount(2, $fields);
        $this->assertSame(str_repeat('1', 1024), $fields[0]['value']);
        $this->assertSame('Field 2', $fields[1]['name']);
        $this->assertSame(1904 - 1031 - 7, mb_strlen($fields[1]['value']));
    }

    public function testAFieldWhoseNameNoLongerFitsIsDroppedWithTheFollowingOnes(): void
    {
        $embed = [
            'description' => str_repeat('d', 4096),
            'fields' => [
                ['name' => 'A', 'value' => str_repeat('a', 1024)],
                ['name' => 'B', 'value' => str_repeat('b', 870)],
                ['name' => 'Long name', 'value' => 'x'],
                ['name' => 'C', 'value' => 'c'],
            ],
        ];

        $fields = $this->limiter->limit([$embed])[0]['fields'];

        // 4096 + 1025 + 871 = 5992: 8 characters left, not enough for "Long name" and some value; "C" would fit, but
        // only after a missing field
        $this->assertSame(['A', 'B'], array_column($fields, 'name'));
    }

    public function testAnEmbedWithoutTextCostsNothingAndTheEmbedsAfterTheLastCharacterAreDropped(): void
    {
        $embeds = [
            ['image' => ['url' => 'https://valdi.test/banner.png']],
            ['description' => str_repeat('a', 4096)],
            ['title' => str_repeat('t', 256), 'description' => str_repeat('b', 1648)],
            ['fields' => [['name' => 'Too late', 'value' => 'x']], 'color' => 1],
        ];

        $limited = $this->limiter->limit($embeds);

        $this->assertCount(3, $limited);
        $this->assertSame(['image' => ['url' => 'https://valdi.test/banner.png']], $limited[0]);
        $this->assertSame(6000, array_sum(array_map(DiscordEmbedLimiter::countText(...), $limited)));
    }

    public function testAnEmbedWhoseOnlyFieldDoesNotFitIsDropped(): void
    {
        $embeds = [
            ['description' => str_repeat('a', 4096)],
            ['description' => str_repeat('b', 1900)],
            ['fields' => [['name' => 'Name', 'value' => 'v']], 'color' => 1],
        ];

        $limited = $this->limiter->limit($embeds);

        // 4 characters left: the name (4) would leave nothing for the value
        $this->assertCount(2, $limited);
    }
}

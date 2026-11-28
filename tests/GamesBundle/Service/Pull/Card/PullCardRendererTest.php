<?php

namespace App\Tests\GamesBundle\Service\Pull\Card;

use App\GamesBundle\Exception\PullCardUnavailableException;
use App\GamesBundle\Model\Pull\Card\PullCard;
use App\GamesBundle\Model\Pull\Card\PullCardItem;
use App\GamesBundle\Model\Pull\Card\PullCardSection;
use App\GamesBundle\Service\Pull\Card\PullCardIconInliner;
use App\GamesBundle\Service\Pull\Card\PullCardRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class PullCardRendererTest extends TestCase
{
    private const string ICON_PATH = 'hkrpg_global/id/character/1005.webp';
    private const string MISSING_ICON_PATH = 'hkrpg_global/id/character/1006.webp';
    private const string ICON_DATA_URI = 'data:image/png;base64,iVBORw0KGgo=';
    private const string FONT_DIR = __DIR__.'/../../../../../src/GamesBundle/resources/fonts/ubuntu';

    /** @var \ArrayObject<int, string> icon paths asked to the inliner */
    private \ArrayObject $inlinedPaths;

    protected function setUp(): void
    {
        $this->inlinedPaths = new \ArrayObject();
    }

    public function testRenderHtmlEscapesTheNames(): void
    {
        $card = self::card(
            [self::section([new PullCardItem('<b>Kafka</b>', 70)])],
            title: '<b>All</b> banners',
            playerName: '<b>Me</b>',
        );

        $body = $this->body($this->createRenderer()->renderHtml($card));

        $this->assertStringContainsString('&lt;b&gt;Kafka&lt;/b&gt;', $body);
        $this->assertStringContainsString('&lt;b&gt;Me&lt;/b&gt;', $body);
        $this->assertStringContainsString('&lt;b&gt;All&lt;/b&gt; banners', $body);
        $this->assertStringNotContainsString('<b>', $body);
    }

    public function testRenderHtmlEmbedsTheFont(): void
    {
        $head = $this->head($this->createRenderer()->renderHtml(self::card([self::section([])])));

        // 2 subsets (latin, latin-ext) x 3 weights
        $this->assertSame(6, preg_match_all('/@font-face \{([^}]*)\}/', $head, $matches));
        $weights = [];
        foreach ($matches[1] as $rule) {
            // assertTrue: a failure would dump the embedded font
            $this->assertTrue(str_contains($rule, 'font-family: "PullCardSans";'), 'Family of the embedded font');
            $this->assertTrue(str_contains($rule, 'src: url("data:font/woff2;base64,'), 'WOFF2 data URI');
            $this->assertTrue(str_contains($rule, 'format("woff2");'), 'WOFF2 format');
            $this->assertSame(1, preg_match('/unicode-range: U\+[0-9A-F]/', $rule), 'Unicode range of the subset');
            $this->assertSame(1, preg_match('/font-weight: (\d+);/', $rule, $weight));
            $weights[] = (int) $weight[1];
        }
        sort($weights);
        $this->assertSame([400, 400, 500, 500, 700, 700], $weights, 'Every weight, once per subset');

        $font = file_get_contents(self::FONT_DIR.'/ubuntu-latin-400-normal.woff2');
        $this->assertNotFalse($font);
        // assertTrue: a failure would dump the whole document
        $this->assertTrue(
            str_contains($head, 'data:font/woff2;base64,'.base64_encode($font).'")'),
            'The font file is embedded as is',
        );
    }

    public function testRenderHtmlInlinesTheIconsAndFallsBackOnTheInitial(): void
    {
        $card = self::card([self::section([
            new PullCardItem('Kafka', 70, iconPath: self::ICON_PATH),
            new PullCardItem('silver Wolf', 80, iconPath: self::MISSING_ICON_PATH),
            new PullCardItem('blade', 75),
            // Same icon twice: inlined once
            new PullCardItem('Kafka', 60, iconPath: self::ICON_PATH),
        ])]);

        $body = $this->body($this->createRenderer()->renderHtml($card));

        $this->assertSame(2, substr_count($body, sprintf('<img src="%s" alt="">', self::ICON_DATA_URI)));
        $this->assertStringContainsString('<span class="initial">S</span>', $body, 'The icon could not be loaded');
        $this->assertStringContainsString('<span class="initial">B</span>', $body, 'The item has no icon');
        $this->assertStringNotContainsString('<span class="initial">K</span>', $body);
        $this->assertSame([self::ICON_PATH, self::MISSING_ICON_PATH], $this->inlinedPaths->getArrayCopy());
    }

    public function testRenderHtmlShowsTheBadgesOfAnItem(): void
    {
        $card = self::card([self::section([
            new PullCardItem('Kafka', 70, isUp: true, isFree: true, isManual: true, hasDeclaredPity: true),
            new PullCardItem('Bronya', 80, isUp: false),
        ])]);

        $body = $this->body($this->createRenderer()->renderHtml($card));

        $this->assertSame(1, substr_count($body, '<span class="badge up">UP</span>'));
        $this->assertSame(1, substr_count($body, '<span class="badge free">FREE</span>'));
        $this->assertStringContainsString('<span class="badge pity">70*</span>', $body);
        $this->assertStringContainsString('<span class="badge pity">80</span>', $body);
        $this->assertStringContainsString('class="ring manual free"', $body);
    }

    #[DataProvider('hiddenCountProvider')]
    public function testRenderHtmlShowsTheOlderCellOnlyWithHiddenPulls(int $hiddenCount, bool $expected): void
    {
        $card = self::card([self::section([new PullCardItem('Kafka', 70)], hiddenCount: $hiddenCount)]);

        $body = $this->body($this->createRenderer()->renderHtml($card));

        $this->assertSame($expected, str_contains($body, 'class="cell more"'));
        $this->assertSame($expected, str_contains($body, sprintf('<span>+%d</span>', $hiddenCount)));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function hiddenCountProvider(): iterable
    {
        yield 'none hidden' => [0, false];
        yield 'some hidden' => [3, true];
    }

    #[DataProvider('playerProvider')]
    public function testRenderHtmlShowsThePlayerLineOnlyWithANameOrUid(?string $playerName, ?string $playerUid, ?string $expected): void
    {
        $card = self::card([self::section([])], playerName: $playerName, playerUid: $playerUid);

        $body = $this->body($this->createRenderer()->renderHtml($card));

        if ($expected === null) {
            $this->assertStringNotContainsString('class="player"', $body);
            $this->assertStringNotContainsString('UID', $body);
        } else {
            $this->assertStringContainsString(sprintf('<div class="player">%s</div>', $expected), $body);
        }
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string}>
     */
    public static function playerProvider(): iterable
    {
        yield 'hidden' => [null, null, null];
        yield 'name only' => ['Trailblazer', null, 'Trailblazer'];
        yield 'UID only' => [null, '800000001', 'UID 800000001'];
        yield 'name and UID' => ['Trailblazer', '800000001', 'Trailblazer · UID 800000001'];
    }

    public function testRenderHtmlPrefersThePullLabelOfTheSection(): void
    {
        $card = self::card([
            self::section([], name: 'Arc banner', pullLabel: 'Arcs'),
            self::section([], name: 'Standard banner'),
        ], pullLabel: 'Wishes');

        $body = $this->body($this->createRenderer()->renderHtml($card));

        $this->assertStringContainsString('Total Arcs', $body);
        $this->assertStringContainsString('No 5★ arcs yet', $body);
        $this->assertStringContainsString('Total Wishes', $body);
        $this->assertStringContainsString('No 5★ wishes yet', $body);
        // Several sections: each one is named
        $this->assertStringContainsString('<div class="section-name">Arc banner</div>', $body);
        $this->assertStringContainsString('<div class="section-name">Standard banner</div>', $body);
    }

    public function testRenderHtmlHidesTheSectionNameOfASingleSection(): void
    {
        $body = $this->body($this->createRenderer()->renderHtml(self::card([self::section([], name: 'Arc banner')])));

        $this->assertStringNotContainsString('class="section-name"', $body);
    }

    public function testRenderHtmlHidesTheRateUpWhenUnknown(): void
    {
        $unknown = $this->body($this->createRenderer()->renderHtml(self::card([self::section([], upCount: 0, upKnown: 0)])));
        $known = $this->body($this->createRenderer()->renderHtml(self::card([self::section([], upCount: 3, upKnown: 4)])));

        $this->assertStringNotContainsString('Rate-up', $unknown);
        $this->assertStringContainsString('Rate-up', $known);
        $this->assertStringContainsString('3<small>/4 · 75%</small>', $known);
    }

    public function testRenderPngPostsTheHtmlToNodeServices(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('PNG-BYTES', ['http_code' => 200]);
        }, 'http://127.0.0.1:3003');
        $renderer = $this->createRenderer($client);
        $card = self::card([self::section([new PullCardItem('Kafka', 70, iconPath: self::ICON_PATH)])]);

        $png = $renderer->renderPng($card);

        $this->assertSame('PNG-BYTES', $png);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('http://127.0.0.1:3003/render/png', $requests[0]['url']);
        $this->assertSame(['Content-Type: application/json'], $requests[0]['options']['normalized_headers']['content-type'] ?? null);

        $body = json_decode($requests[0]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertSame(['html', 'width', 'scale', 'selector'], array_keys($body));
        $this->assertSame(1200, $body['width']);
        $this->assertSame(2, $body['scale']);
        $this->assertSame('#card', $body['selector']);
        // assertTrue: a failure would dump the whole document twice
        $this->assertTrue($body['html'] === $renderer->renderHtml($card), 'The HTML sent is the one of renderHtml()');
    }

    #[DataProvider('failureProvider')]
    public function testRenderPngThrowsWhenNodeServicesCannotRender(MockResponse $response, bool $transportError): void
    {
        $renderer = $this->createRenderer(new MockHttpClient($response, 'http://127.0.0.1:3003'));

        try {
            $renderer->renderPng(self::card([self::section([])]));
            $this->fail('A failed render must throw.');
        } catch (PullCardUnavailableException $e) {
            $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $e->getStatusCode());
            $this->assertSame(PullCardUnavailableException::MESSAGE, $e->getMessage());
            if ($transportError) {
                $this->assertInstanceOf(TransportExceptionInterface::class, $e->getPrevious());
            } else {
                $this->assertNull($e->getPrevious());
            }
        }
    }

    /**
     * @return iterable<string, array{MockResponse, bool}>
     */
    public static function failureProvider(): iterable
    {
        yield 'server error' => [new MockResponse('Render failed', ['http_code' => 500]), false];
        yield 'bad request' => [new MockResponse('{"error":"html is required"}', ['http_code' => 400]), false];
        yield 'unexpected success code' => [new MockResponse('', ['http_code' => 204]), false];
        yield 'node-services down' => [new MockResponse('', ['error' => 'Connection refused']), true];
    }

    public function testRenderResponseIsAPrivatePngDownload(): void
    {
        $renderer = $this->createRenderer(new MockHttpClient(new MockResponse('PNG-BYTES', ['http_code' => 200]), 'http://127.0.0.1:3003'));
        $card = self::card([self::section([])], gameName: 'Genshin Impact', title: "Beginners' Wish");

        $response = $renderer->renderResponse($card);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('PNG-BYTES', $response->getContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename=genshin-impact-beginners-wish.png', $response->headers->get('Content-Disposition'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function testRenderWebpConvertsThePngOfNodeServices(): void
    {
        $scales = [];
        $renderer = $this->createRenderer($this->pngClient([2 => [60, 40]], $scales));

        $image = $renderer->renderWebp(self::card([self::section([])]));

        $this->assertSame([2], $scales, 'Rendered at 2x by default');
        $this->assertSame('image/webp', $image->mimeType);
        $this->assertSame('webp', $image->extension);
        $this->assertSame([60, 40], [$image->width, $image->height]);
        $this->assertSame(['RIFF', 'WEBP'], [substr($image->content, 0, 4), substr($image->content, 8, 4)], 'A WebP file');

        $decoded = new \Imagick();
        $decoded->readImageBlob($image->content);
        $this->assertSame('WEBP', $decoded->getImageFormat());
        $this->assertSame([60, 40], [$decoded->getImageWidth(), $decoded->getImageHeight()]);
        $decoded->clear();
    }

    public function testRenderWebpSendsTheScaleAsked(): void
    {
        $scales = [];
        $renderer = $this->createRenderer($this->pngClient([1 => [30, 20]], $scales));

        $image = $renderer->renderWebp(self::card([self::section([])]), 1);

        $this->assertSame([1], $scales);
        $this->assertSame([30, 20], [$image->width, $image->height]);
    }

    public function testRenderWebpKeepsACardAsTallAsAWebpCanBe(): void
    {
        $scales = [];
        $renderer = $this->createRenderer($this->pngClient([2 => [2, PullCardRenderer::WEBP_MAX_SIDE]], $scales));

        $image = $renderer->renderWebp(self::card([self::section([])]));

        $this->assertSame([2], $scales, 'No render at 1x');
        $this->assertSame([2, PullCardRenderer::WEBP_MAX_SIDE], [$image->width, $image->height]);
    }

    /**
     * @return iterable<string, array{array{int, int}}>
     */
    public static function oversizedProvider(): iterable
    {
        yield 'too tall' => [[4, PullCardRenderer::WEBP_MAX_SIDE + 1]];
        yield 'too wide' => [[PullCardRenderer::WEBP_MAX_SIDE + 1, 4]];
    }

    /**
     * @param array{int, int} $oversized
     */
    #[DataProvider('oversizedProvider')]
    public function testRenderWebpRendersAgainAtOneXWhenTooLargeAtTwoX(array $oversized): void
    {
        $scales = [];
        $renderer = $this->createRenderer($this->pngClient([2 => $oversized, 1 => [2, 8192]], $scales));

        $image = $renderer->renderWebp(self::card([self::section([])]));

        $this->assertSame([2, 1], $scales);
        $this->assertSame([2, 8192], [$image->width, $image->height], 'The image of the 1x render');
        $this->assertSame('image/webp', $image->mimeType);
    }

    public function testRenderWebpRefusesACardTooTallEvenAtOneX(): void
    {
        $scales = [];
        $tall = [2, PullCardRenderer::WEBP_MAX_SIDE + 1];
        $renderer = $this->createRenderer($this->pngClient([2 => $tall, 1 => $tall], $scales));

        try {
            $renderer->renderWebp(self::card([self::section([])]));
            $this->fail('A card too tall for a WebP must be refused.');
        } catch (PullCardUnavailableException $e) {
            $this->assertSame(PullCardUnavailableException::MESSAGE, $e->getMessage());
            // The size, not a conversion error
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame([2, 1], $scales);
    }

    public function testRenderWebpAtOneXIsNotRenderedAgain(): void
    {
        $scales = [];
        $renderer = $this->createRenderer($this->pngClient([1 => [2, PullCardRenderer::WEBP_MAX_SIDE + 1]], $scales));

        try {
            $renderer->renderWebp(self::card([self::section([])]), 1);
            $this->fail('A card too tall for a WebP must be refused.');
        } catch (PullCardUnavailableException $e) {
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame([1], $scales);
    }

    public function testRenderWebpThrowsWhenTheResponseIsNotAnImage(): void
    {
        $renderer = $this->createRenderer(new MockHttpClient(new MockResponse('PNG-BYTES', ['http_code' => 200]), 'http://127.0.0.1:3003'));

        try {
            $renderer->renderWebp(self::card([self::section([])]));
            $this->fail('An unreadable PNG must throw.');
        } catch (PullCardUnavailableException $e) {
            $this->assertSame(PullCardUnavailableException::MESSAGE, $e->getMessage());
            $this->assertInstanceOf(\ImagickException::class, $e->getPrevious());
        }
    }

    public function testRenderWebpThrowsWhenNodeServicesCannotRender(): void
    {
        $renderer = $this->createRenderer(new MockHttpClient(new MockResponse('Render failed', ['http_code' => 500]), 'http://127.0.0.1:3003'));

        $this->expectException(PullCardUnavailableException::class);
        $this->expectExceptionMessage(PullCardUnavailableException::MESSAGE);

        $renderer->renderWebp(self::card([self::section([])]));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function filenameProvider(): iterable
    {
        yield 'apostrophe' => ['Genshin Impact', "Beginners' Wish", 'webp', 'genshin-impact-beginners-wish.webp'];
        yield 'colon and capitals' => ['Honkai: Star Rail', 'All banners', 'png', 'honkai-star-rail-all-banners.png'];
        yield 'parentheses' => ['Neverness to Everness', 'Scarborough Fair (Limited Boards)', 'webp', 'neverness-to-everness-scarborough-fair-limited-boards.webp'];
    }

    #[DataProvider('filenameProvider')]
    public function testFilenameIsTheSlugOfTheGameAndTheTitle(string $gameName, string $title, string $extension, string $expected): void
    {
        $card = self::card([self::section([])], gameName: $gameName, title: $title);

        $this->assertSame($expected, $this->createRenderer()->filename($card, $extension));
    }

    /**
     * node-services answering with a PNG of the size given for the scale asked; the scales asked are recorded.
     *
     * @param array<int, array{int, int}> $sizesByScale width and height of the PNG by scale
     * @param list<int>                   $scales
     */
    private function pngClient(array $sizesByScale, array &$scales): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($sizesByScale, &$scales): MockResponse {
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($body);
            $scale = $body['scale'];
            $this->assertIsInt($scale);
            $scales[] = $scale;
            $this->assertArrayHasKey($scale, $sizesByScale, sprintf('Unexpected scale %d', $scale));

            return new MockResponse(self::png(...$sizesByScale[$scale]), ['http_code' => 200]);
        }, 'http://127.0.0.1:3003');
    }

    private static function png(int $width, int $height): string
    {
        $image = new \Imagick();
        try {
            $image->newImage($width, $height, new \ImagickPixel('#1e293b'));
            $image->setImageFormat('png');

            return $image->getImagesBlob();
        } finally {
            $image->clear();
        }
    }

    private function createRenderer(?MockHttpClient $nodeServices = null): PullCardRenderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../../../src/GamesBundle/templates', 'Games');
        $twig = new Environment($loader, ['strict_variables' => true]);

        // Data URI for ICON_PATH only, as if every other icon failed to load
        $inliner = new class($this->inlinedPaths, self::ICON_PATH, self::ICON_DATA_URI) extends PullCardIconInliner {
            /**
             * @param \ArrayObject<int, string> $inlinedPaths
             */
            public function __construct(
                private readonly \ArrayObject $inlinedPaths,
                private readonly string $iconPath,
                private readonly string $iconDataUri,
            ) {
            }

            public function dataUri(string $iconPath): ?string
            {
                $this->inlinedPaths[] = $iconPath;

                return $iconPath === $this->iconPath ? $this->iconDataUri : null;
            }
        };

        return new PullCardRenderer(
            $twig,
            $inliner,
            $nodeServices ?? new MockHttpClient(new MockResponse('PNG-BYTES', ['http_code' => 200]), 'http://127.0.0.1:3003'),
            new AsciiSlugger(),
        );
    }

    /**
     * @param list<PullCardSection> $sections
     */
    private static function card(
        array $sections,
        string $gameName = 'Honkai: Star Rail',
        string $title = 'All banners',
        string $pullLabel = 'Warps',
        ?string $playerName = null,
        ?string $playerUid = null,
    ): PullCard {
        return new PullCard(
            gameName: $gameName,
            title: $title,
            sLabel: '5★',
            pullLabel: $pullLabel,
            sections: $sections,
            generatedAt: new \DateTimeImmutable('2026-11-27 12:00:00'),
            playerName: $playerName,
            playerUid: $playerUid,
        );
    }

    /**
     * @param list<PullCardItem> $items
     */
    private static function section(
        array $items,
        string $name = 'Character Event Warp',
        int $hiddenCount = 0,
        int $upCount = 0,
        int $upKnown = 0,
        ?string $pullLabel = null,
    ): PullCardSection {
        return new PullCardSection(
            name: $name,
            totalPulls: 1234,
            sCount: count($items) + $hiddenCount,
            avgSPity: 72.5,
            sPity: 30,
            sMaxPity: 90,
            items: $items,
            hiddenCount: $hiddenCount,
            upCount: $upCount,
            upKnown: $upKnown,
            pullLabel: $pullLabel,
        );
    }

    private function head(string $html): string
    {
        $this->assertStringContainsString('<body>', $html);

        return explode('<body>', $html, 2)[0];
    }

    private function body(string $html): string
    {
        $this->assertStringContainsString('<body>', $html);

        return explode('<body>', $html, 2)[1];
    }
}

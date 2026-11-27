<?php

namespace App\Tests\GamesBundle\Service\Pull\Card;

use App\GamesBundle\Service\Pull\Card\PullCardIconInliner;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Liip\ImagineBundle\Service\FilterService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PullCardIconInlinerTest extends TestCase
{
    private const string ICON_PATH = 'hkrpg_global/id/character/1005.webp';
    /** 1x1 transparent PNG */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    /** 1x1 lossless WebP */
    private const string WEBP_BASE64 = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

    private string $webRoot;

    protected function setUp(): void
    {
        // A directory of its own: other test runs may go on in parallel
        $this->webRoot = sprintf('%s/pull-card-inliner-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        mkdir($this->webRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->webRoot)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->webRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->webRoot);
    }

    /**
     * The file name says WebP: the MIME type comes from the content.
     */
    #[DataProvider('imageProvider')]
    public function testReturnsTheCachedIconAsADataUri(string $base64, string $expectedMimeType): void
    {
        $content = base64_decode($base64, true);
        $this->assertNotFalse($content);
        $this->writeCachedIcon(self::ICON_PATH, $content);

        $filterService = $this->createMock(FilterService::class);
        $filterService->expects($this->once())
            ->method('warmUpCache')
            ->with(self::ICON_PATH, 'games_pull_item_icon')
            ->willReturn(false);

        $dataUri = new PullCardIconInliner($filterService, $this->webRoot)->dataUri(self::ICON_PATH);

        $this->assertSame(sprintf('data:%s;base64,%s', $expectedMimeType, base64_encode($content)), $dataUri);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function imageProvider(): iterable
    {
        yield 'PNG' => [self::PNG_BASE64, 'image/png'];
        yield 'WebP' => [self::WEBP_BASE64, 'image/webp'];
    }

    public function testReadsTheCacheOfAPathWithALeadingSlash(): void
    {
        $content = (string) base64_decode(self::PNG_BASE64, true);
        $this->writeCachedIcon(self::ICON_PATH, $content);

        $dataUri = new PullCardIconInliner($this->createStub(FilterService::class), $this->webRoot)->dataUri('/'.self::ICON_PATH);

        $this->assertSame('data:image/png;base64,'.base64_encode($content), $dataUri);
    }

    public function testReturnsNullWhenTheIconCannotBeLoaded(): void
    {
        // Even an icon cached earlier is not read once the warm-up fails
        $this->writeCachedIcon(self::ICON_PATH, (string) base64_decode(self::PNG_BASE64, true));
        $filterService = $this->createStub(FilterService::class);
        $filterService->method('warmUpCache')->willThrowException(new NotLoadableException('Source image not resolvable'));

        $this->assertNull(new PullCardIconInliner($filterService, $this->webRoot)->dataUri(self::ICON_PATH));
    }

    public function testReturnsNullWhenTheIconIsMissingFromTheCache(): void
    {
        $this->assertNull(new PullCardIconInliner($this->createStub(FilterService::class), $this->webRoot)->dataUri(self::ICON_PATH));
    }

    public function testReturnsNullWhenTheCachedIconIsEmpty(): void
    {
        $this->writeCachedIcon(self::ICON_PATH, '');

        $this->assertNull(new PullCardIconInliner($this->createStub(FilterService::class), $this->webRoot)->dataUri(self::ICON_PATH));
    }

    /**
     * Writes the icon where the web_path resolver of LiipImagine keeps it.
     */
    private function writeCachedIcon(string $path, string $content): void
    {
        $file = sprintf('%s/media/cache/games_pull_item_icon/%s', $this->webRoot, $path);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, $content);
    }
}

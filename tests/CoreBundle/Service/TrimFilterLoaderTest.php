<?php

namespace App\Tests\CoreBundle\Service;

use App\CoreBundle\Service\TrimFilterLoader;
use App\GamesBundle\Service\PullItemIconUrlBuilder;
use Imagine\Imagick\Imagine;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "trim" filter, alone and through the real games_pull_item_preview filter set (trim, then thumbnail).
 */
class TrimFilterLoaderTest extends KernelTestCase
{
    public function testPreviewFilterSetTrimsTheTransparentBordersBeforeResizing(): void
    {
        // A 2048x1024 splash whose subject fills only 1200x900 in the middle
        $binary = $this->createPng(2048, 1024, 1200, 900, transparent: true);

        $result = $this->filterManager()->applyFilter($binary, PullItemIconUrlBuilder::PREVIEW_FILTER_NAME);

        $this->assertSame('image/webp', $result->getMimeType());
        // Without the trim the inset thumbnail would give 1024x512, with the subject only 600 px wide
        $this->assertSame([1024, 768], $this->sizeOf($result->getContent()));
    }

    /**
     * Many artworks spread a haze of nearly invisible pixels over the whole canvas (e.g. Genshin's Odette): it is cut
     * like the empty canvas.
     */
    public function testTheNearlyTransparentHazeIsTrimmedToo(): void
    {
        $binary = $this->createPng(2048, 1024, 1200, 900, transparent: true, hazeOpacity: 0.02);

        $result = $this->filterManager()->applyFilter($binary, PullItemIconUrlBuilder::PREVIEW_FILTER_NAME);

        $this->assertSame([1024, 768], $this->sizeOf($result->getContent()));
    }

    public function testAVisibleGlowIsKept(): void
    {
        // A 10% opaque glow is part of the artwork: it stays (1624x1024 with the subject), only the empty right side goes
        $binary = $this->createPng(2048, 1024, 1200, 900, transparent: true, hazeOpacity: 0.1);

        $result = $this->filterManager()->applyFilter($binary, PullItemIconUrlBuilder::PREVIEW_FILTER_NAME);

        $this->assertSame([1024, 646], $this->sizeOf($result->getContent()));
    }

    public function testImagesWithoutTransparentBordersAreLeftAsTheyAre(): void
    {
        $image = (new Imagine())->load($this->createPng(300, 200, 100, 100, transparent: false)->getContent());
        $image->getImagick()->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

        $trimmed = (new TrimFilterLoader())->load($image);

        $this->assertSame(300, $trimmed->getSize()->getWidth());
        $this->assertSame(200, $trimmed->getSize()->getHeight());
    }

    public function testPreviewFilterSetNeverEnlargesASmallImage(): void
    {
        $binary = $this->createPng(400, 400, 398, 358, transparent: true);

        $result = $this->filterManager()->applyFilter($binary, PullItemIconUrlBuilder::PREVIEW_FILTER_NAME);

        $this->assertSame([398, 358], $this->sizeOf($result->getContent()));
    }

    public function testImagesWithoutAlphaChannelAreLeftAsTheyAre(): void
    {
        $image = (new Imagine())->load($this->createPng(300, 200, 100, 100, transparent: false)->getContent());
        $image->getImagick()->setImageAlphaChannel(\Imagick::ALPHACHANNEL_DEACTIVATE);

        $trimmed = (new TrimFilterLoader())->load($image);

        $this->assertSame(300, $trimmed->getSize()->getWidth());
        $this->assertSame(200, $trimmed->getSize()->getHeight());
    }

    private function filterManager(): FilterManager
    {
        return static::getContainer()->get('liip_imagine.filter.manager');
    }

    /**
     * PNG of the given size with an opaque centred rectangle, on a transparent or white background, optionally with
     * a haze of the given opacity over the left half of the canvas (from the left edge to the subject).
     */
    private function createPng(int $width, int $height, int $subjectWidth, int $subjectHeight, bool $transparent, float $hazeOpacity = 0.0): Binary
    {
        $imagick = new \Imagick();
        $imagick->newImage($width, $height, new \ImagickPixel($transparent ? 'transparent' : 'white'));
        if ($hazeOpacity > 0) {
            $haze = new \ImagickDraw();
            $haze->setFillColor(new \ImagickPixel(sprintf('rgba(120, 160, 255, %F)', $hazeOpacity)));
            $haze->rectangle(0, 0, intdiv($width, 2), $height - 1);
            $imagick->drawImage($haze);
        }
        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel('#c0392b'));
        $left = intdiv($width - $subjectWidth, 2);
        $top = intdiv($height - $subjectHeight, 2);
        $draw->rectangle($left, $top, $left + $subjectWidth - 1, $top + $subjectHeight - 1);
        $imagick->drawImage($draw);
        $imagick->setImageFormat('png');

        return new Binary($imagick->getImageBlob(), 'image/png', 'png');
    }

    /**
     * @return array{int, int}
     */
    private function sizeOf(string $content): array
    {
        $imagick = new \Imagick();
        $imagick->readImageBlob($content);

        return [$imagick->getImageWidth(), $imagick->getImageHeight()];
    }
}

<?php

namespace App\CoreBundle\Service;

use Imagine\Image\ImageInterface;
use Imagine\Imagick\Image as ImagickImage;
use Liip\ImagineBundle\Imagine\Filter\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * LiipImagine "trim" filter: removes the transparent borders of an image, e.g. the empty canvas around a character
 * artwork, so that a following thumbnail filter enlarges the subject instead of the margins.
 *
 * Options: threshold, the opacity (0 to 1, default 0.05) up to which a pixel counts as transparent. Artworks often
 * spread a haze of nearly invisible pixels (1-3% opaque) over the whole canvas: a plain trim would keep it all.
 * Only images with an alpha channel are trimmed, and only with the imagick driver: anything else is returned as it is.
 */
#[AutoconfigureTag('liip_imagine.filter.loader', attributes: ['loader' => 'trim'])]
class TrimFilterLoader implements LoaderInterface
{
    private const float DEFAULT_THRESHOLD = 0.05;

    public function load(ImageInterface $image, array $options = []): ImageInterface
    {
        if (!$image instanceof ImagickImage) {
            return $image;
        }

        $imagick = $image->getImagick();
        if (!$imagick->getImageAlphaChannel()) {
            return $image;
        }

        try {
            $box = $this->findVisibleBox($imagick, (float) ($options['threshold'] ?? self::DEFAULT_THRESHOLD));
            if ($box !== null) {
                $imagick->cropImage($box['width'], $box['height'], $box['x'], $box['y']);
                // Drops the virtual canvas left by the crop, as "+repage" does
                $imagick->setImagePage(0, 0, 0, 0);
            }
        } catch (\ImagickException) {
            // Keep the image as it is
        }

        return $image;
    }

    /**
     * Bounding box of the pixels more opaque than the threshold; null when there is nothing to cut (nothing visible,
     * or already tight).
     *
     * @return array{width: int, height: int, x: int, y: int}|null
     */
    private function findVisibleBox(\Imagick $imagick, float $threshold): ?array
    {
        $mask = clone $imagick;

        try {
            // Opacity as a grey image, then white where visible and black where (nearly) transparent
            $mask->setImageAlphaChannel(\Imagick::ALPHACHANNEL_EXTRACT);
            $mask->thresholdImage($threshold * \Imagick::getQuantum());
            // The trim cuts the borders of the corner colour: a 1px black frame makes it the transparent one
            $mask->borderImage(new \ImagickPixel('black'), 1, 1);

            $mask->trimImage(0);
            $page = $mask->getImagePage();
            $box = [
                'width' => $mask->getImageWidth(),
                'height' => $mask->getImageHeight(),
                'x' => max(0, (int) $page['x'] - 1),
                'y' => max(0, (int) $page['y'] - 1),
            ];
        } finally {
            $mask->clear();
        }

        // A fully transparent image trims down to a single pixel
        if ($box['width'] <= 1 && $box['height'] <= 1) {
            return null;
        }
        if ($box['width'] === $imagick->getImageWidth() && $box['height'] === $imagick->getImageHeight()) {
            return null;
        }

        return $box;
    }
}

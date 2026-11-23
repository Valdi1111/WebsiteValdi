<?php

namespace App\Tests\CoreBundle;

/**
 * Image files for the profile image tests, written with Imagick (the LiipImagine driver) into a given folder.
 */
final class TestImages
{
    public static function image(string $dir, string $format, int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'), $format);

        return self::write($dir, $format, $image->getImageBlob());
    }

    /**
     * A JPEG with an EXIF Orientation tag (6: the camera was turned, the image must be rotated 90° clockwise).
     * Imagick does not write EXIF, so the APP1 segment is built here: a big-endian TIFF header and one IFD entry.
     */
    public static function jpegWithOrientation(string $dir, int $width, int $height, int $orientation): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'), 'jpeg');
        $jpeg = $image->getImageBlob();

        $tiff = "MM\x00\x2A".pack('N', 8)
            .pack('n', 1).pack('nnNn', 0x0112, 3, 1, $orientation)."\x00\x00"
            .pack('N', 0);
        $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff;

        // Right after the SOI marker
        return self::write($dir, 'jpeg', substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    }

    /**
     * A PNG whose header (type, dimensions) is valid but whose pixel data is cut.
     */
    public static function truncatedPng(string $dir): string
    {
        $image = new \Imagick();
        $image->newPseudoImage(300, 300, 'plasma:');
        $image->setImageFormat('png');
        $blob = $image->getImageBlob();

        return self::write($dir, 'png', substr($blob, 0, intdiv(strlen($blob), 3)));
    }

    public static function animatedWebp(string $dir): string
    {
        $animation = new \Imagick();
        // Different colors: the WebP encoder merges identical frames
        foreach (['#ff0000', '#0000ff'] as $color) {
            $frame = new \Imagick();
            $frame->newImage(200, 200, new \ImagickPixel($color), 'webp');
            $animation->addImage($frame);
        }
        $animation->setFormat('webp');

        return self::write($dir, 'webp', $animation->getImagesBlob());
    }

    /**
     * @return array{int, int, string} width, height and MIME type of an image given as bytes
     */
    public static function describe(string $content): array
    {
        $size = getimagesizefromstring($content);
        if ($size === false) {
            throw new \RuntimeException('Not an image.');
        }

        return [$size[0], $size[1], $size['mime']];
    }

    /**
     * @return array<string, string> the EXIF properties Imagick reads from an image given as bytes
     */
    public static function exif(string $content): array
    {
        $image = new \Imagick();
        $image->readImageBlob($content);

        return $image->getImageProperties('exif:*');
    }

    private static function write(string $dir, string $extension, string $content): string
    {
        $path = $dir.'/'.bin2hex(random_bytes(4)).'.'.$extension;
        file_put_contents($path, $content);

        return $path;
    }
}

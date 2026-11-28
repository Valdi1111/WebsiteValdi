<?php

namespace App\GamesBundle\Model\Pull\Card;

/**
 * A rendered pulls card: the image file and its size in pixels.
 */
final readonly class PullCardImage
{
    public function __construct(
        public string $content,
        public string $mimeType,
        public string $extension,
        public int $width,
        public int $height,
    ) {
    }
}

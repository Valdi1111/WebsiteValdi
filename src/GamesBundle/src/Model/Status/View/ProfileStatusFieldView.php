<?php

namespace App\GamesBundle\Model\Status\View;

/**
 * One field of the full view of a section, as the embeds show it.
 */
final readonly class ProfileStatusFieldView
{
    public function __construct(
        public string $name,
        public string $value,
        public ?string $icon = null,
        public bool $inline = false,
    ) {
    }
}

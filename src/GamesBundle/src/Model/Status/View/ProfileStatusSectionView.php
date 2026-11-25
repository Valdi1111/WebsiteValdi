<?php

namespace App\GamesBundle\Model\Status\View;

/**
 * One section of a profile status on the site: the line of the compact view ($value, $attention) and, as details, the
 * full view of the section without the profile header.
 *
 * A section that could not be read or shown is not $available: its value is the generic line and it has no details.
 */
final readonly class ProfileStatusSectionView
{
    /**
     * @param list<ProfileStatusFieldView> $fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public bool $available,
        public bool $attention,
        public string $value,
        public ?string $description = null,
        public array $fields = [],
    ) {
    }
}

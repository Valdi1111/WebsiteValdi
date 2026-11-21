<?php

namespace App\GryphlineBundle\Model;

/**
 * Identity of the Gryphline account an account token belongs to (step 1 of the SKPort OAuth flow).
 */
final readonly class SkportAccountInfo
{
    public function __construct(
        public ?string $hgId,
        public ?string $nickname,
    ) {
    }
}

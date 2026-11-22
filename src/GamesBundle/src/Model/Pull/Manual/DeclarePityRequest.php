<?php

namespace App\GamesBundle\Model\Pull\Manual;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PATCH .../pulls/{pull}/declared-pity (operation A). A null pity removes the correction.
 */
final readonly class DeclarePityRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: 200)]
        public ?int $pity = null,
    ) {
    }
}

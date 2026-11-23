<?php

namespace App\CoreBundle\Model\Profile;

use App\CoreBundle\Entity\Token;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/me/tokens.
 */
final readonly class CreateApiTokenPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'Describe what the token is for.', normalizer: 'trim')]
        #[Assert\Length(max: Token::DESCRIPTION_MAX_LENGTH)]
        public string $description = '',
    ) {
    }
}

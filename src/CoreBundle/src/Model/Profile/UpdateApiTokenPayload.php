<?php

namespace App\CoreBundle\Model\Profile;

use App\CoreBundle\Entity\Token;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PATCH /api/me/tokens/{id}: the fields to change, the missing ones stay as they are.
 */
final readonly class UpdateApiTokenPayload
{
    /**
     * @param bool|null $valid false disables the token, true enables it again
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Describe what the token is for.', allowNull: true, normalizer: 'trim')]
        #[Assert\Length(max: Token::DESCRIPTION_MAX_LENGTH)]
        public ?string $description = null,
        public ?bool $valid = null,
    ) {
    }

    /**
     * @return array{description?: string, valid?: bool}
     */
    public function getChanges(): array
    {
        return array_filter([
            'description' => $this->description === null ? null : trim($this->description),
            'valid' => $this->valid,
        ], static fn (string|bool|null $value): bool => $value !== null);
    }
}

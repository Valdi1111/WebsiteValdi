<?php

namespace App\CoreBundle\Model\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PATCH /api/admin/users/{id}.
 */
final readonly class UpdateUserPayload
{
    /**
     * @param bool|null $enabled false disables the account, true enables it again
     */
    public function __construct(
        #[Assert\NotNull(message: 'Say whether the account is enabled.')]
        public ?bool $enabled = null,
    ) {
    }
}

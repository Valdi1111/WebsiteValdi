<?php

namespace App\CoreBundle\Model\Admin;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Validator\NewPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/admin/users. That the email is free and the roles exist is checked by AdminUserManager::create().
 */
final readonly class CreateUserPayload
{
    /**
     * @param string|null  $password initial password chosen by the admin, null for none: in every case the user can
     *                               choose one from the welcome email
     * @param list<string> $roles    names of the roles to assign
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Enter the email address.', normalizer: 'trim')]
        #[Assert\Email(normalizer: 'trim')]
        #[Assert\Length(max: User::EMAIL_MAX_LENGTH, normalizer: 'trim')]
        public string $email = '',
        #[Assert\When(expression: 'value !== null', constraints: [new NewPassword()])]
        #[\SensitiveParameter]
        public ?string $password = null,
        #[Assert\All([new Assert\Type('string')])]
        public array $roles = [],
    ) {
    }
}

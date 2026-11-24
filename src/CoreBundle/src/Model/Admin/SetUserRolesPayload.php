<?php

namespace App\CoreBundle\Model\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PUT /api/admin/users/{id}/roles: the whole set of roles to assign, the missing ones are removed. That the
 * roles exist is checked by AdminUserManager::setRoles().
 */
final readonly class SetUserRolesPayload
{
    /**
     * @param list<string>|null $roles role names; required, so that a body without it never removes every role
     */
    public function __construct(
        #[Assert\NotNull(message: 'Give the roles of the user, an empty list for none.')]
        #[Assert\All([new Assert\Type('string')])]
        public ?array $roles = null,
    ) {
    }
}

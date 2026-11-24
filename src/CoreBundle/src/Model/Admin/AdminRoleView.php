<?php

namespace App\CoreBundle\Model\Admin;

use App\CoreBundle\Entity\Role;

/**
 * A role that an admin can give to a user (GET /api/admin/roles).
 */
final readonly class AdminRoleView
{
    /**
     * @param string      $name   role code, e.g. ROLE_USER_BOOKS
     * @param string      $label  display name (Role::$description)
     * @param string|null $bundle bundle the role belongs to (e.g. BooksBundle), null for the site-wide ones
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $bundle,
    ) {
    }

    public static function fromRole(Role $role): self
    {
        return new self((string) $role->getName(), (string) $role->getDescription(), $role->getBundle());
    }
}

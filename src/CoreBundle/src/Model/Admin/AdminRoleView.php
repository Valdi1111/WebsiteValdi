<?php

namespace App\CoreBundle\Model\Admin;

use App\CoreBundle\Entity\Role;

/**
 * A role that an admin can give to a user (GET /api/admin/roles), with what it brings through the role hierarchy:
 * the page shows the effective roles of a set while it is being edited.
 */
final readonly class AdminRoleView
{
    /**
     * @param string       $name    role code, e.g. ROLE_USER_BOOKS
     * @param string       $label   display name (Role::$description)
     * @param string|null  $bundle  bundle the role belongs to (e.g. BooksBundle), null for the site-wide ones
     * @param list<string> $implies roles it brings through the hierarchy, also indirectly, without itself; sorted
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $bundle,
        public array $implies,
    ) {
    }

    /**
     * @param list<string> $implies
     */
    public static function fromRole(Role $role, array $implies): self
    {
        return new self((string) $role->getName(), (string) $role->getDescription(), $role->getBundle(), $implies);
    }
}

<?php

namespace App\CoreBundle\Model\Profile;

/**
 * A role of the logged-in user as the profile page shows it (GET /api/me/roles).
 */
final readonly class RoleView
{
    /**
     * @param string      $name     role code, e.g. ROLE_USER_BOOKS
     * @param string      $label    display name (Role::$description), the code when the role has no entity
     * @param string|null $bundle   bundle the role belongs to (e.g. BooksBundle), null for the site-wide ones
     * @param bool        $assigned true when given to the user directly, false when inherited through the hierarchy
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $bundle,
        public bool $assigned,
    ) {
    }
}

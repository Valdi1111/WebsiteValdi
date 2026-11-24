<?php

namespace App\CoreBundle\Exception;

/**
 * Roles to assign that are not in the role table. The message is shown to the admin.
 */
class UnknownRoleException extends \RuntimeException
{
    /**
     * @param list<string> $names
     */
    public function __construct(public readonly array $names)
    {
        parent::__construct(sprintf('Unknown role: %s.', implode(', ', $names)));
    }
}

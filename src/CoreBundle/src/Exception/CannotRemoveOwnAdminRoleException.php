<?php

namespace App\CoreBundle\Exception;

/**
 * An admin tried to change their own roles into a set without the admin role (assigned or inherited): they would lose
 * the admin page, and the last admin would leave nobody to give it back. The message is shown to the admin.
 */
class CannotRemoveOwnAdminRoleException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('You cannot remove the admin role from yourself.');
    }
}

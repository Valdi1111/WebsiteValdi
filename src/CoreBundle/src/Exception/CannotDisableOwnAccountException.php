<?php

namespace App\CoreBundle\Exception;

/**
 * An admin tried to disable their own account: it would log them out for good, and the last admin could lock everyone
 * out of the admin page. The message is shown to the admin.
 */
class CannotDisableOwnAccountException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('You cannot disable your own account.');
    }
}

<?php

namespace App\CoreBundle\Exception;

/**
 * The current password given to change it is wrong. The message is shown to the user.
 */
class InvalidCurrentPasswordException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The current password is wrong.');
    }
}

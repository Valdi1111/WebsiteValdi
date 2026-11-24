<?php

namespace App\CoreBundle\Exception;

/**
 * Another user already has this email (User::$email is unique). The message is shown to the admin.
 */
class EmailAlreadyUsedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('A user with this email already exists.', 0, $previous);
    }
}

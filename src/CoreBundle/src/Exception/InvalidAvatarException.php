<?php

namespace App\CoreBundle\Exception;

/**
 * The uploaded profile image passed the header checks (type, size, dimensions) but cannot be used:
 * animated, or not decodable. The message is shown to the user.
 */
class InvalidAvatarException extends \RuntimeException
{
}

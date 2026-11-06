<?php

namespace App\HoyoverseBundle\Message;

use App\CoreBundle\Message\TaskMessageInterface;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Update cookie for all accounts.
 */
#[AsMessage('hoyoverse')]
class UpdateCookieMessage implements TaskMessageInterface
{

}

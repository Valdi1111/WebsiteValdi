<?php

namespace App\CoreBundle\Model\Chat;

/**
 * How an answer reaches the user.
 */
enum ChatReplyMode: string
{
    /** Edits the message of the request: the deferred answer of a command, or the message whose button was pressed */
    case Replace = 'replace';
    /** A new message only the user sees, leaving the original untouched: e.g. a failed button press */
    case FollowUp = 'follow_up';
}

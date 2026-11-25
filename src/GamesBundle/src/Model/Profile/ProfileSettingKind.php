<?php

namespace App\GamesBundle\Model\Profile;

/**
 * What a profile setting turns on: a reminder sent to the user, an action the site takes for them, or a data sync.
 */
enum ProfileSettingKind: string
{
    case Reminder = 'reminder';
    case Automation = 'automation';
    case Sync = 'sync';
}

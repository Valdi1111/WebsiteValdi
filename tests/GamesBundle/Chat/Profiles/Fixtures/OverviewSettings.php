<?php

namespace App\Tests\GamesBundle\Chat\Profiles\Fixtures;

use App\GamesBundle\Model\Game\HasCheckInStatusInterface;
use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Model\Profile\ProfileSettingKind;

/**
 * Settings of the fake provider over the fields of OverviewProfile, one of each shape: automations, reminders with and
 * without threshold, the endgames reminder with its targets, a sync, and one no fake game supports (weeklies).
 */
final class OverviewSettings
{
    /**
     * @return list<ProfileSetting>
     */
    public static function all(): array
    {
        return [
            new ProfileSetting('check_in', 'Daily check-in', ProfileSettingKind::Automation, HasCheckInStatusInterface::class, 'checkIn'),
            new ProfileSetting('code_redeem', 'Code redemption', ProfileSettingKind::Automation, HasCheckInStatusInterface::class, 'codeRedeem'),
            new ProfileSetting('stamina', 'Stamina reminder', ProfileSettingKind::Reminder, HasStaminaInterface::class, 'staminaCheck', 'staminaThreshold'),
            new ProfileSetting('realm_currency', 'Realm currency reminder', ProfileSettingKind::Reminder, HasStaminaInterface::class, 'currencyCheck', 'currencyThreshold'),
            new ProfileSetting('dailies', 'Dailies reminder', ProfileSettingKind::Reminder, HasDailiesInterface::class, 'dailiesCheck'),
            new ProfileSetting('weeklies', 'Weeklies reminder', ProfileSettingKind::Reminder, HasWeekliesInterface::class, 'weekliesCheck'),
            new ProfileSetting('endgames', 'Endgames reminder', ProfileSettingKind::Reminder, HasEndgamesInterface::class, 'endgamesCheck', extraFields: ['endgamesSettings']),
            new ProfileSetting('sync_pulls', 'Pulls sync', ProfileSettingKind::Sync, HasPullSyncInterface::class, 'syncPulls'),
        ];
    }
}

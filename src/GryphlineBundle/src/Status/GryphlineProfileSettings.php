<?php

namespace App\GryphlineBundle\Status;

use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Model\Profile\ProfileSettingKind;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Model\Game\HasCheckInInterface;

/**
 * Settings of a Gryphline game profile (GryphlineGameProfile fields), in the order of the settings drawer.
 *
 * Dependency-free, like HoyoverseProfileSettings: the profile normalizer reads it.
 */
final class GryphlineProfileSettings
{
    /**
     * @return list<ProfileSetting>
     */
    public static function all(): array
    {
        return [
            new ProfileSetting('check_in', 'Daily check-in', ProfileSettingKind::Automation, HasCheckInInterface::class, 'skportCheckIn'),
            new ProfileSetting('missed_check_in', 'Missed check-in', ProfileSettingKind::Automation, HasCheckInInterface::class, 'skportMissedCheckIn'),
            new ProfileSetting('stamina', 'Stamina reminder', ProfileSettingKind::Reminder, HasStaminaInterface::class, 'staminaCheck', 'staminaThreshold'),
            new ProfileSetting('dailies', 'Dailies reminder', ProfileSettingKind::Reminder, HasDailiesInterface::class, 'dailiesCheck'),
            new ProfileSetting('weeklies', 'Weeklies reminder', ProfileSettingKind::Reminder, HasWeekliesInterface::class, 'weekliesCheck'),
            new ProfileSetting('sync_pulls', 'Pulls sync', ProfileSettingKind::Sync, HasAutoPullImportInterface::class, 'syncPulls'),
        ];
    }
}

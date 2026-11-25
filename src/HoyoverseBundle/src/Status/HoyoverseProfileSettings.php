<?php

namespace App\HoyoverseBundle\Status;

use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Model\Profile\ProfileSettingKind;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasHilichurlInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\Game\HasMimoInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;

/**
 * Settings of a HoYoverse game profile (HoyoverseGameProfile fields), in the order of the settings drawer.
 *
 * Dependency-free on purpose: the profile normalizer reads it, and it cannot depend on the profile provider (the
 * provider needs the cookie utils, which need the serializer, which needs the normalizer).
 */
final class HoyoverseProfileSettings
{
    /**
     * @return list<ProfileSetting>
     */
    public static function all(): array
    {
        return [
            new ProfileSetting('check_in', 'Daily check-in', ProfileSettingKind::Automation, HasHoyolabCheckInInterface::class, 'hoyolabCheckIn'),
            new ProfileSetting('missed_check_in', 'Missed check-in', ProfileSettingKind::Automation, HasHoyolabCheckInInterface::class, 'hoyolabMissedCheckIn'),
            new ProfileSetting('code_redeem', 'Code redemption', ProfileSettingKind::Automation, HasCodeRedemptionInterface::class, 'codeRedeem'),
            new ProfileSetting('stamina', 'Stamina reminder', ProfileSettingKind::Reminder, HasStaminaInterface::class, 'staminaCheck', 'staminaThreshold'),
            new ProfileSetting('expeditions', 'Expeditions reminder', ProfileSettingKind::Reminder, HasExpeditionsInterface::class, 'expeditionsCheck'),
            new ProfileSetting('realm_currency', 'Realm currency reminder', ProfileSettingKind::Reminder, HasRealmCurrencyInterface::class, 'realmCurrencyCheck', 'realmCurrencyThreshold'),
            new ProfileSetting('shop_status', 'Shop reminder', ProfileSettingKind::Reminder, HasShopStatusInterface::class, 'shopStatusCheck'),
            new ProfileSetting('mimo', 'Traveling Mimo', ProfileSettingKind::Automation, HasMimoInterface::class, 'mimoCheck', extraFields: ['mimoRedeem', 'mimoRedeemDraw', 'mimoLottery', 'mimoReservePoints']),
            new ProfileSetting('hilichurl', "Hilichurl's Treasure Trove", ProfileSettingKind::Automation, HasHilichurlInterface::class, 'hilichurlCheck', extraFields: ['hilichurlRedeem']),
            new ProfileSetting('dailies', 'Dailies reminder', ProfileSettingKind::Reminder, HasDailiesInterface::class, 'dailiesCheck'),
            new ProfileSetting('weeklies', 'Weeklies reminder', ProfileSettingKind::Reminder, HasWeekliesInterface::class, 'weekliesCheck'),
            new ProfileSetting('endgames', 'Endgames reminder', ProfileSettingKind::Reminder, HasEndgamesInterface::class, 'endgamesCheck', extraFields: ['endgamesSettings']),
            new ProfileSetting('sync_diary', 'Diary sync', ProfileSettingKind::Sync, HasDiaryInterface::class, 'syncDiary'),
            new ProfileSetting('sync_pulls', 'Pulls sync', ProfileSettingKind::Sync, HasStokenPullImportInterface::class, 'syncPulls'),
        ];
    }
}

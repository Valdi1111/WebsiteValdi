<?php

namespace App\Tests\GamesBundle\Status;

use App\GamesBundle\Model\Game\HasDailiesInterface;
use App\GamesBundle\Model\Game\HasEndgamesInterface;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GryphlineBundle\Model\Game\HasAutoPullImportInterface;
use App\GryphlineBundle\Model\Game\HasCheckInInterface;
use App\GryphlineBundle\Status\GryphlineProfileSettings;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasHilichurlInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\Game\HasMimoInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\Game\HasStokenPullImportInterface;
use App\HoyoverseBundle\Status\HoyoverseProfileSettings;
use PHPUnit\Framework\TestCase;

/**
 * The settings of each provider are the single definition of what the profile normalizers expose and accept: the map
 * derived from them must stay the CONDITIONAL_SETTINGS the normalizers held before, fields and order included.
 */
class ProfileSettingsSchemaTest extends TestCase
{
    public function testHoyoverseFieldsAreTheFormerNormalizerMap(): void
    {
        $this->assertSame([
            HasHoyolabCheckInInterface::class => ['hoyolabCheckIn', 'hoyolabMissedCheckIn'],
            HasCodeRedemptionInterface::class => ['codeRedeem'],
            HasStaminaInterface::class => ['staminaCheck', 'staminaThreshold'],
            HasExpeditionsInterface::class => ['expeditionsCheck'],
            HasRealmCurrencyInterface::class => ['realmCurrencyCheck', 'realmCurrencyThreshold'],
            HasShopStatusInterface::class => ['shopStatusCheck'],
            HasMimoInterface::class => ['mimoCheck', 'mimoRedeem', 'mimoRedeemDraw', 'mimoLottery', 'mimoReservePoints'],
            HasHilichurlInterface::class => ['hilichurlCheck', 'hilichurlRedeem'],
            HasDailiesInterface::class => ['dailiesCheck'],
            HasWeekliesInterface::class => ['weekliesCheck'],
            HasEndgamesInterface::class => ['endgamesCheck', 'endgamesSettings'],
            HasDiaryInterface::class => ['syncDiary'],
            HasStokenPullImportInterface::class => ['syncPulls'],
        ], ProfileSetting::fieldsByCapability(HoyoverseProfileSettings::all()));
    }

    public function testGryphlineFieldsAreTheFormerNormalizerMap(): void
    {
        $this->assertSame([
            HasCheckInInterface::class => ['skportCheckIn', 'skportMissedCheckIn'],
            HasStaminaInterface::class => ['staminaCheck', 'staminaThreshold'],
            HasDailiesInterface::class => ['dailiesCheck'],
            HasWeekliesInterface::class => ['weekliesCheck'],
            HasAutoPullImportInterface::class => ['syncPulls'],
        ], ProfileSetting::fieldsByCapability(GryphlineProfileSettings::all()));
    }

    public function testKeysAreUniqueAndSharedAcrossProviders(): void
    {
        foreach ([HoyoverseProfileSettings::all(), GryphlineProfileSettings::all()] as $settings) {
            $keys = array_map(static fn (ProfileSetting $setting): string => $setting->key, $settings);
            $this->assertSame(array_unique($keys), $keys);
            // The same feature has the same key on every provider, whatever its field
            $this->assertContains('check_in', $keys);
            $this->assertContains('stamina', $keys);
        }
    }
}

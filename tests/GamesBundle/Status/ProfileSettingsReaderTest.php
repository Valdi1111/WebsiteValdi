<?php

namespace App\Tests\GamesBundle\Status;

use App\CoreBundle\Service\UserRoleResolver;
use App\GamesBundle\Model\Game\HasStaminaInterface;
use App\GamesBundle\Model\Game\HasWeekliesInterface;
use App\GamesBundle\Model\Profile\ProfileSetting;
use App\GamesBundle\Model\Profile\ProfileSettingKind;
use App\GamesBundle\Service\Profile\GameProfileRegistry;
use App\GamesBundle\Service\Profile\ProfileSettingsReader;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Status\GryphlineProfileSettings;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Status\HoyoverseProfileSettings;
use App\Tests\GamesBundle\Status\Fixtures\FakeGameProfile;
use App\Tests\GamesBundle\Status\Fixtures\FakeNotesGameService;
use App\Tests\GamesBundle\Status\Fixtures\FakeProfileProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

class ProfileSettingsReaderTest extends TestCase
{
    public function testOnlyTheSettingsOfTheCapabilitiesOfTheGame(): void
    {
        $provider = new FakeProfileProvider();
        $provider->services = ['fake_game' => new FakeNotesGameService()];
        // Fields of FakeGameProfile: active (isActive), staminaThreshold, gameName
        $provider->settings = [
            new ProfileSetting('stamina', 'Stamina reminder', ProfileSettingKind::Reminder, HasStaminaInterface::class, 'active', 'staminaThreshold', ['gameName']),
            new ProfileSetting('weeklies', 'Weeklies reminder', ProfileSettingKind::Reminder, HasWeekliesInterface::class, 'active'),
        ];
        $reader = new ProfileSettingsReader(
            new GameProfileRegistry([$provider], $this->createStub(UserRoleResolver::class), ['fake' => 'ROLE_FAKE']),
            PropertyAccess::createPropertyAccessor(),
        );
        $profile = FakeGameProfile::ownedBy(GameProfileRegistryTest::user(1), 1)->setStaminaThreshold(160);

        $values = $reader->read($provider->createContext($profile));

        $this->assertSame(['stamina'], array_keys($values), 'the game has no weeklies');
        $this->assertTrue($values['stamina']->enabled);
        $this->assertSame(160, $values['stamina']->threshold);
        $this->assertSame(['gameName' => 'Fake Game'], $values['stamina']->extra);
    }

    /**
     * @return iterable<string, array{class-string, list<ProfileSetting>}>
     */
    public static function providerSettings(): iterable
    {
        yield 'hoyoverse' => [HoyoverseGameProfile::class, HoyoverseProfileSettings::all()];
        yield 'gryphline' => [GryphlineGameProfile::class, GryphlineProfileSettings::all()];
    }

    /**
     * Every field of the definitions reads on the entity (isStaminaCheck(), getShopStatusCheck()...), or the status
     * layer would fail on the first profile.
     *
     * @param class-string           $profileClass
     * @param list<ProfileSetting> $settings
     */
    #[DataProvider('providerSettings')]
    public function testEveryFieldReadsOnTheProfileEntity(string $profileClass, array $settings): void
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $profile = new $profileClass();

        foreach ($settings as $setting) {
            foreach ($setting->getFields() as $field) {
                $this->assertTrue($accessor->isReadable($profile, $field), sprintf('%s::%s', $profileClass, $field));
            }
        }
    }
}

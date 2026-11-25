<?php

namespace App\Tests\GamesBundle\Normalizer;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameProfileInterface;
use App\GamesBundle\Service\Status\GameProfileStatusCollector;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * status_sections of the normalized game profiles, through the serializer of the container: the profile normalizers
 * reach the status collector lazily, since the game providers behind it need the serializer.
 */
class ProfileStatusSectionsNormalizationTest extends KernelTestCase
{
    private const string COOKIE = 'ltoken_v2=v2_test; ltuid_v2=100000001; ltmid_v2=mid_test';

    /**
     * @return list<string>
     */
    private static function statusSections(GameProfileInterface $profile): array
    {
        $result = static::getContainer()->get(NormalizerInterface::class)->normalize($profile, 'json');
        self::assertIsArray($result);
        self::assertArrayHasKey('status_sections', $result);

        return $result['status_sections'];
    }

    private static function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }

    private static function hoyoverseProfile(string $cookie): HoyoverseGameProfile
    {
        $account = self::withId((new HoyoverseAccount())->setUser(new User())->setCookie($cookie), 1);

        return self::withId((new HoyoverseGameProfile())
            ->setAccount($account)
            ->setGameId(GenshinImpactService::GAME_ID)
            ->setGameBiz(GenshinImpactService::GAME_BIZ), 10);
    }

    public function testHoyoverseProfileListsTheSectionsOfItsGame(): void
    {
        $sections = self::statusSections(self::hoyoverseProfile(self::COOKIE));

        $this->assertSame(['stamina', 'dailies', 'weeklies', 'expeditions', 'realm_currency', 'check_in', 'endgames'], $sections);
    }

    public function testCollectorBuiltBeforeTheSerializer(): void
    {
        // The other order of the lazy edge: the collector (and the providers) first
        $collector = static::getContainer()->get(GameProfileStatusCollector::class);

        $this->assertSame(
            $collector->getSupportedSectionKeys(self::hoyoverseProfile(self::COOKIE)),
            self::statusSections(self::hoyoverseProfile(self::COOKIE)),
        );
    }

    public function testProfileWithoutUsableCookieHasNoSection(): void
    {
        $this->assertSame([], self::statusSections(self::hoyoverseProfile('ltoken_v2=v2_test')));
        $this->assertSame([], self::statusSections(self::hoyoverseProfile('')));
    }

    public function testGryphlineProfileListsTheSectionsOfItsGame(): void
    {
        $account = self::withId((new GryphlineAccount())->setUser(new User()), 2);
        $profile = self::withId((new GryphlineGameProfile())
            ->setAccount($account)
            ->setGameId(ArknightsEndfieldService::GAME_ID)
            ->setGameBiz(ArknightsEndfieldService::GAME_BIZ), 20);

        $this->assertSame(['stamina', 'dailies', 'weeklies', 'check_in'], self::statusSections($profile));
    }

    public function testPerfectworldProfileHasNoSection(): void
    {
        $account = self::withId((new PerfectworldAccount())->setUser(new User()), 3);
        $profile = self::withId((new PerfectworldGameProfile())
            ->setAccount($account)
            ->setGameId(NevernessToEvernessService::GAME_ID)
            ->setGameBiz(NevernessToEvernessService::GAME_BIZ), 30);

        $this->assertSame([], self::statusSections($profile));
    }
}

<?php

namespace App\Tests\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Pull\PullSyncSource;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\Pull\PullSyncSourceResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The automatic pull sync depends on the game and on the account cookie, read with the real cookie parser.
 */
class PullSyncSourceResolverTest extends TestCase
{
    private const string HOYOLAB = 'ltoken_v2=v2_lt; ltuid_v2=111; ltmid_v2=mid111';
    private const string REDEMPTION = '; cookie_token_v2=v2_ct; account_id_v2=111; account_mid_v2=mid111';
    private const string STOKEN = '; stoken_v2=v2_st';

    private function resolver(): PullSyncSourceResolver
    {
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory))]);

        return new PullSyncSourceResolver(
            new HoyolabCookieUtilsService(new MockHttpClient(), new NullLogger(), $serializer, $this->createStub(HoyolabUtilsService::class))
        );
    }

    private static function profile(?string $cookie): HoyoverseGameProfile
    {
        $profile = new HoyoverseGameProfile();
        if ($cookie !== null) {
            $profile->setAccount((new HoyoverseAccount())->setCookie($cookie));
        }

        return $profile;
    }

    /**
     * @return iterable<string, array{class-string<GameInterface>, ?string, ?PullSyncSource}>
     */
    public static function sourceProvider(): iterable
    {
        yield 'ZZZ with stoken_v2 keeps getGachaLog' => [ZenlessZoneZeroService::class, self::HOYOLAB . self::REDEMPTION . self::STOKEN, PullSyncSource::Stoken];
        yield 'ZZZ with HoYoLAB cookies only' => [ZenlessZoneZeroService::class, self::HOYOLAB, PullSyncSource::Chronicle];
        yield 'ZZZ with HoYoLAB and redemption cookies' => [ZenlessZoneZeroService::class, self::HOYOLAB . self::REDEMPTION, PullSyncSource::Chronicle];
        yield 'ZZZ with an incomplete cookie' => [ZenlessZoneZeroService::class, 'ltuid_v2=111; ltmid_v2=mid111' . self::STOKEN, null];
        yield 'ZZZ with an empty cookie' => [ZenlessZoneZeroService::class, '', null];
        yield 'ZZZ without account' => [ZenlessZoneZeroService::class, null, null];
        yield 'Genshin with stoken_v2' => [GenshinImpactService::class, self::HOYOLAB . self::STOKEN, PullSyncSource::Stoken];
        yield 'Genshin without stoken_v2 stays excluded' => [GenshinImpactService::class, self::HOYOLAB . self::REDEMPTION, null];
        yield 'Star Rail with stoken_v2' => [HonkaiStarRailService::class, self::HOYOLAB . self::STOKEN, null];
        yield 'Star Rail with HoYoLAB cookies only' => [HonkaiStarRailService::class, self::HOYOLAB, null];
        yield 'game without pull tracker' => [HonkaiImpact3rdService::class, self::HOYOLAB . self::STOKEN, null];
    }

    /**
     * @param class-string<GameInterface> $gameClass
     */
    #[DataProvider('sourceProvider')]
    public function testResolvesTheSourceFromTheGameAndTheAccountCookie(string $gameClass, ?string $cookie, ?PullSyncSource $expected): void
    {
        $resolver = $this->resolver();
        $gameService = new $gameClass(new NullLogger());

        $this->assertSame($expected, $resolver->resolveForProfile($gameService, self::profile($cookie)));
    }

    public function testAMissingParsedCookieCannotSync(): void
    {
        $this->assertNull($this->resolver()->resolve(new ZenlessZoneZeroService(new NullLogger()), null));
    }
}

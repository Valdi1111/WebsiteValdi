<?php

namespace App\Tests\HoyoverseBundle\Normalizer;

use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Game\RarityTierConfig;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Normalizer\HoyoverseGameProfileNormalizer;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use App\HoyoverseBundle\Service\Pull\PullSyncSourceResolver;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Capability flags consumed by the shared games SPA (PullsPage, GameProfileCard).
 */
class HoyoverseGameProfileNormalizerTest extends TestCase
{
    private const string HOYOLAB_COOKIE = 'ltoken_v2=v2_lt; ltuid_v2=111; ltmid_v2=mid111';
    private const string STOKEN_COOKIE = self::HOYOLAB_COOKIE . '; stoken_v2=v2_st';

    private function createDummyObjectNormalizer(int $gameId): NormalizerInterface&DenormalizerInterface
    {
        return new class($gameId) implements NormalizerInterface, DenormalizerInterface {
            public ?array $normalizeContext = null;

            public function __construct(private readonly int $gameId)
            {
            }

            public function normalize(mixed $data, ?string $format = null, array $context = []): array
            {
                $this->normalizeContext = $context;
                return ['id' => 1, 'game_id' => $this->gameId];
            }

            public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
            {
                return true;
            }

            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
            {
                return $data;
            }

            public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
            {
                return true;
            }

            public function getSupportedTypes(?string $format): array
            {
                return ['*' => false];
            }
        };
    }

    private function normalizeFor(int $gameId, object $gameService, string $cookie = self::STOKEN_COOKIE): array
    {
        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($gameService);

        // Real cookie parsing: the sync flags depend on the tokens of the account cookie
        $metadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new ObjectNormalizer($metadataFactory, new MetadataAwareNameConverter($metadataFactory))]);
        $cookieUtils = new HoyolabCookieUtilsService(new MockHttpClient(), new NullLogger(), $serializer, $this->createStub(HoyolabUtilsService::class));

        $normalizer = new HoyoverseGameProfileNormalizer($this->createDummyObjectNormalizer($gameId), $locator, new PullSyncSourceResolver($cookieUtils));
        $profile = (new HoyoverseGameProfile())
            ->setGameId($gameId)
            ->setAccount((new HoyoverseAccount())->setCookie($cookie));
        $result = $normalizer->normalize($profile);
        $this->assertIsArray($result);

        return $result;
    }

    public function testStokenGameSupportsAutomaticPullSync(): void
    {
        $gameService = $this->createStub(GenshinImpactService::class);
        $gameService->method('getRarityConfig')->willReturn(new RarityConfig(
            new RarityTierConfig(5, '5★', '5★'),
            new RarityTierConfig(4, '4★', '4★'),
            new RarityTierConfig(3, '3★', '3★'),
            'Wishes',
            'Wish',
            false,
        ));

        $result = $this->normalizeFor(GenshinImpactService::GAME_ID, $gameService);

        $this->assertTrue($result['supports_pulls']);
        $this->assertTrue($result['supports_manual_pulls']);
        $this->assertTrue($result['supports_pull_sync']);
        $this->assertSame('stoken', $result['pull_sync_source']);
        $this->assertTrue($result['supports_powershell_pulls']);
        $this->assertSame('Wishes', $result['pull_label']);
        $this->assertArrayNotHasKey('supports_stoken_pulls', $result);
    }

    public function testStokenOnlyGameHasNoAutomaticPullSyncWithoutStoken(): void
    {
        $result = $this->normalizeFor(GenshinImpactService::GAME_ID, $this->createStub(GenshinImpactService::class), self::HOYOLAB_COOKIE);

        $this->assertFalse($result['supports_pull_sync']);
        $this->assertNull($result['pull_sync_source']);
        $this->assertTrue($result['supports_powershell_pulls']);
    }

    public function testChronicleGameSupportsAutomaticPullSyncWithHoyolabCookiesOnly(): void
    {
        $result = $this->normalizeFor(ZenlessZoneZeroService::GAME_ID, $this->createStub(ZenlessZoneZeroService::class), self::HOYOLAB_COOKIE);

        $this->assertTrue($result['supports_pulls']);
        $this->assertTrue($result['supports_pull_sync']);
        $this->assertSame('chronicle', $result['pull_sync_source']);
    }

    public function testChronicleGameKeepsTheStokenSourceWhenTheCookieHasStoken(): void
    {
        $result = $this->normalizeFor(ZenlessZoneZeroService::GAME_ID, $this->createStub(ZenlessZoneZeroService::class));

        $this->assertTrue($result['supports_pull_sync']);
        $this->assertSame('stoken', $result['pull_sync_source']);
    }

    public function testPowershellOnlyGameHasNoAutomaticPullSync(): void
    {
        $result = $this->normalizeFor(HonkaiStarRailService::GAME_ID, $this->createStub(HonkaiStarRailService::class));

        $this->assertFalse($result['supports_pull_sync']);
        $this->assertNull($result['pull_sync_source']);
        $this->assertTrue($result['supports_powershell_pulls']);
        $this->assertTrue($result['supports_manual_pulls']);
    }

    public function testEndgameSettingsAreExposedOnlyForGamesWithEndgames(): void
    {
        foreach ([
            [ZenlessZoneZeroService::GAME_ID, $this->createStub(ZenlessZoneZeroService::class), true],
            [GenshinImpactService::GAME_ID, $this->createStub(GenshinImpactService::class), false],
            [HonkaiStarRailService::GAME_ID, $this->createStub(HonkaiStarRailService::class), false],
        ] as [$gameId, $gameService, $supported]) {
            $locator = $this->createStub(ServiceCollectionInterface::class);
            $locator->method('has')->willReturn(true);
            $locator->method('get')->willReturn($gameService);
            $objectNormalizer = $this->createDummyObjectNormalizer($gameId);

            (new HoyoverseGameProfileNormalizer($objectNormalizer, $locator, $this->createStub(PullSyncSourceResolver::class)))->normalize((new HoyoverseGameProfile())->setGameId($gameId));

            $ignored = $objectNormalizer->normalizeContext[AbstractNormalizer::IGNORED_ATTRIBUTES] ?? [];
            foreach (['endgamesCheck', 'endgamesFullClear'] as $field) {
                $this->assertSame(!$supported, in_array($field, $ignored, true), sprintf('%s for game %d', $field, $gameId));
            }
        }
    }

    public function testGameWithoutPullTrackerHasNoManualPulls(): void
    {
        $result = $this->normalizeFor(HonkaiImpact3rdService::GAME_ID, $this->createStub(HonkaiImpact3rdService::class));

        $this->assertFalse($result['supports_pulls']);
        $this->assertFalse($result['supports_manual_pulls']);
    }
}

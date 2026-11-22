<?php

namespace App\Tests\HoyoverseBundle\Normalizer;

use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Game\RarityTierConfig;
use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Normalizer\HoyoverseGameProfileNormalizer;
use App\HoyoverseBundle\Service\GenshinImpactService;
use App\HoyoverseBundle\Service\HonkaiImpact3rdService;
use App\HoyoverseBundle\Service\HonkaiStarRailService;
use App\HoyoverseBundle\Service\ZenlessZoneZeroService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

/**
 * Capability flags consumed by the shared games SPA (PullsPage, GameProfileCard).
 */
class HoyoverseGameProfileNormalizerTest extends TestCase
{
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

    private function normalizeFor(int $gameId, object $gameService): array
    {
        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($gameService);

        $normalizer = new HoyoverseGameProfileNormalizer($this->createDummyObjectNormalizer($gameId), $locator);
        $result = $normalizer->normalize((new HoyoverseGameProfile())->setGameId($gameId));
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
        $this->assertTrue($result['supports_powershell_pulls']);
        $this->assertSame('Wishes', $result['pull_label']);
        $this->assertArrayNotHasKey('supports_stoken_pulls', $result);
    }

    public function testPowershellOnlyGameHasNoAutomaticPullSync(): void
    {
        $result = $this->normalizeFor(HonkaiStarRailService::GAME_ID, $this->createStub(HonkaiStarRailService::class));

        $this->assertFalse($result['supports_pull_sync']);
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

            (new HoyoverseGameProfileNormalizer($objectNormalizer, $locator))->normalize((new HoyoverseGameProfile())->setGameId($gameId));

            $ignored = $objectNormalizer->normalizeContext[AbstractNormalizer::IGNORED_ATTRIBUTES] ?? [];
            foreach (['endgamesCheck', 'endgamesSettings'] as $field) {
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

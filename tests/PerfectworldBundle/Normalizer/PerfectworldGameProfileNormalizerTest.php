<?php

namespace App\Tests\PerfectworldBundle\Normalizer;

use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Normalizer\PerfectworldGameProfileNormalizer;
use App\PerfectworldBundle\Service\NevernessToEvernessService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class PerfectworldGameProfileNormalizerTest extends TestCase
{
    /**
     * Object normalizer double recording the context it receives.
     */
    private function createObjectNormalizer(array &$contexts): NormalizerInterface&DenormalizerInterface
    {
        return new class($contexts) implements NormalizerInterface, DenormalizerInterface {
            public function __construct(private array &$contexts)
            {
            }

            public function normalize(mixed $data, ?string $format = null, array $context = []): array
            {
                $this->contexts[] = $context;

                return ['id' => 1, 'game_id' => NevernessToEvernessService::GAME_ID];
            }

            public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
            {
                return true;
            }

            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
            {
                $this->contexts[] = $context;

                return $context[AbstractNormalizer::OBJECT_TO_POPULATE] ?? null;
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

    private function createNormalizer(array &$contexts): PerfectworldGameProfileNormalizer
    {
        $game = new NevernessToEvernessService(new NullLogger());

        return new PerfectworldGameProfileNormalizer(
            $this->createObjectNormalizer($contexts),
            new ServiceLocator([(string) NevernessToEvernessService::GAME_ID => static fn () => $game]),
        );
    }

    public function testManualImportProfilesNeverExposePullSync(): void
    {
        $contexts = [];
        $result = $this->createNormalizer($contexts)->normalize(
            (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID)->setParsedTimezone('Etc/GMT-1')
        );

        $this->assertFalse($result['supports_pull_sync']);
        $this->assertTrue($result['supports_pulls']);
        $this->assertTrue($result['supports_manual_pulls']);
        $this->assertSame('Etc/GMT-1', $result['timezone']);
    }

    public function testOnlyActiveIsWritable(): void
    {
        $contexts = [];
        $profile = (new PerfectworldGameProfile())->setGameId(NevernessToEvernessService::GAME_ID);

        $this->createNormalizer($contexts)->denormalize(['active' => false, 'nickname' => 'Hacked'], PerfectworldGameProfile::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $profile,
        ]);

        $this->assertSame(['active'], $contexts[0][AbstractNormalizer::ATTRIBUTES]);
    }
}

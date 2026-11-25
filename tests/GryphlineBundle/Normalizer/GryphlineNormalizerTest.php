<?php

namespace App\Tests\GryphlineBundle\Normalizer;

use App\GamesBundle\Model\Game\RarityConfig;
use App\GamesBundle\Model\Game\RarityTierConfig;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Normalizer\GryphlineAccountNormalizer;
use App\GryphlineBundle\Normalizer\GryphlineGameProfileNormalizer;
use App\GryphlineBundle\Service\ArknightsEndfieldService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class GryphlineNormalizerTest extends TestCase
{
    private function createDummyObjectNormalizer(): NormalizerInterface&DenormalizerInterface
    {
        return new class implements NormalizerInterface, DenormalizerInterface {
            public ?array $normalizedData = null;
            public ?array $denormalizeCapturedContext = null;

            public function normalize(mixed $data, ?string $format = null, array $context = []): array
            {
                return $this->normalizedData ?? ['id' => 10, 'game_id' => ArknightsEndfieldService::GAME_ID, 'skport_check_in' => true, 'skport_missed_check_in' => true];
            }

            public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
            {
                return true;
            }

            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
            {
                $this->denormalizeCapturedContext = $context;
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

    public function testAccountNormalizer(): void
    {
        $dummy = $this->createDummyObjectNormalizer();
        $dummy->normalizedData = ['id' => 1];

        $normalizer = new GryphlineAccountNormalizer($dummy);

        $account = (new GryphlineAccount())
            ->setAccountToken('test-token')
            ->setSessionCred('cred')
            ->setSessionSalt('salt')
            ->setSessionExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->assertTrue($normalizer->supportsNormalization($account));
        $normalized = $normalizer->normalize($account);

        $this->assertIsArray($normalized);
        $this->assertTrue($normalized['is_session_valid']);
        $this->assertTrue($normalized['has_token']);
    }

    public function testGameProfileNormalizerSupportsAndNormalizes(): void
    {
        $dummy = $this->createDummyObjectNormalizer();

        $endfield = $this->createStub(ArknightsEndfieldService::class);
        $endfield->method('getRarityConfig')->willReturn(new RarityConfig(
            new RarityTierConfig(6, '6★', '6★'),
            new RarityTierConfig(5, '5★', '5★'),
            new RarityTierConfig(4, '4★', '4★'),
            'Headhunts',
            'Headhunt',
            true,
        ));

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($endfield);

        $normalizer = new GryphlineGameProfileNormalizer($dummy, $locator);

        $profile = (new GryphlineGameProfile())
            ->setGameId(ArknightsEndfieldService::GAME_ID)
            ->setSkportCheckIn(true)
            ->setSkportMissedCheckIn(true);

        $this->assertTrue($normalizer->supportsNormalization($profile));
        $result = $normalizer->normalize($profile);

        $this->assertIsArray($result);
        $this->assertSame(10, $result['id']);
        $this->assertTrue($result['skport_check_in']);
        $this->assertTrue($result['skport_missed_check_in']);
        // Capability flags shared with other providers by the games SPA
        $this->assertTrue($result['supports_pulls']);
        $this->assertTrue($result['supports_pull_sync']);
        $this->assertTrue($result['supports_manual_pulls']);
        $this->assertSame('Headhunts', $result['pull_label']);
    }

    public function testGameProfileNormalizerDenormalizationWhitelist(): void
    {
        $dummy = $this->createDummyObjectNormalizer();

        $locator = $this->createStub(ServiceCollectionInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($this->createStub(ArknightsEndfieldService::class));

        $normalizer = new GryphlineGameProfileNormalizer($dummy, $locator);

        $profile = (new GryphlineGameProfile())->setGameId(ArknightsEndfieldService::GAME_ID);
        $this->assertTrue($normalizer->supportsDenormalization([], GryphlineGameProfile::class));

        $normalizer->denormalize(
            ['skport_check_in' => false],
            GryphlineGameProfile::class,
            null,
            ['object_to_populate' => $profile]
        );

        $attributes = $dummy->denormalizeCapturedContext['attributes'] ?? [];
        $this->assertContains('active', $attributes);
        $this->assertContains('notificationPlatforms', $attributes);
        $this->assertContains('skportCheckIn', $attributes);
        $this->assertContains('skportMissedCheckIn', $attributes);
        $this->assertContains('staminaCheck', $attributes);
        $this->assertContains('staminaThreshold', $attributes);
        $this->assertContains('dailiesCheck', $attributes);
        $this->assertContains('weekliesCheck', $attributes);
    }
}

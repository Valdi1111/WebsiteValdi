<?php

namespace App\HoyoverseBundle\Normalizer;

use App\HoyoverseBundle\Entity\HoyoverseGameProfile;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasCodeRedemptionInterface;
use App\HoyoverseBundle\Model\Game\HasDailiesInterface;
use App\HoyoverseBundle\Model\Game\HasDiaryInterface;
use App\HoyoverseBundle\Model\Game\HasExpeditionsInterface;
use App\HoyoverseBundle\Model\Game\HasHilichurlInterface;
use App\HoyoverseBundle\Model\Game\HasHoyolabCheckInInterface;
use App\HoyoverseBundle\Model\Game\HasMimoInterface;
use App\HoyoverseBundle\Model\Game\HasRealmCurrencyInterface;
use App\HoyoverseBundle\Model\Game\HasShopStatusInterface;
use App\HoyoverseBundle\Model\Game\HasStaminaInterface;
use App\HoyoverseBundle\Model\Game\HasWeekliesInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

class HoyoverseGameProfileNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public const array CONDITIONAL_SETTINGS = [
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
        HasDiaryInterface::class => ['syncDiary'],
    ];

    /**
     * Base fields that can always be modified by the user regardless of the game.
     */
    public const array BASE_WRITABLE_FIELDS = [
        'active',
        'notificationPlatforms',
    ];

    /**
     * @param ServiceCollectionInterface<GameInterface> $locatorByGameId
     */
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private readonly NormalizerInterface&DenormalizerInterface $objectNormalizer,
        #[AutowireLocator(services: 'hoyoverse.game', defaultIndexMethod: 'getGameId')]
        protected readonly ServiceCollectionInterface $locatorByGameId,
    ) {
    }

    /**
     * Computes the camelCase fields allowed for modification (whitelist) for a specific gameId.
     */
    protected function getAllowedFields(int $gameId): array
    {
        $allowed = self::BASE_WRITABLE_FIELDS;

        if (!$this->locatorByGameId->has($gameId)) {
            return $allowed;
        }

        $gameService = $this->locatorByGameId->get($gameId);
        foreach (self::CONDITIONAL_SETTINGS as $interfaceClass => $fields) {
            if ($gameService instanceof $interfaceClass) {
                foreach ($fields as $field) {
                    $allowed[] = $field;
                }
            }
        }

        return $allowed;
    }

    /**
     * Computes the camelCase fields to ignore during serialization (blacklist) for a specific gameId.
     */
    protected function getIgnoredFields(int $gameId): array
    {
        $ignored = [];

        if (!$this->locatorByGameId->has($gameId)) {
            foreach (self::CONDITIONAL_SETTINGS as $fields) {
                foreach ($fields as $field) {
                    $ignored[] = $field;
                }
            }
            return $ignored;
        }

        $gameService = $this->locatorByGameId->get($gameId);
        foreach (self::CONDITIONAL_SETTINGS as $interfaceClass => $fields) {
            if ($gameService instanceof $interfaceClass) {
                continue;
            }
            foreach ($fields as $field) {
                $ignored[] = $field;
            }
        }

        return $ignored;
    }

    /* -------------------------------------------------------------------------
     * NORMALIZER (Entity -> Array/JSON)
     * ------------------------------------------------------------------------- */

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        if (!$data instanceof HoyoverseGameProfile) {
            throw new InvalidArgumentException(sprintf("The object must be an instance of '%s'.", HoyoverseGameProfile::class));
        }

        $gameId = $data->getGameId();

        // Scope the serializer metadata cache_key per gameId so profiles belonging to
        // different games within the same collection do not share the same cached attributes.
        if (isset($context['cache_key'])) {
            $context['cache_key'] .= '-game-' . $gameId;
        }

        // Apply ignored fields dynamically based on the game's implemented interfaces
        foreach ($this->getIgnoredFields($gameId) as $field) {
            $context[AbstractNormalizer::IGNORED_ATTRIBUTES][] = $field;
        }

        return $this->objectNormalizer->normalize($data, $format, $context);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof HoyoverseGameProfile;
    }

    /* -------------------------------------------------------------------------
     * DENORMALIZER (Array/JSON -> Entity)
     * ------------------------------------------------------------------------- */

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $targetObject = $context[AbstractNormalizer::OBJECT_TO_POPULATE] ?? null;

        $gameId = null;
        if ($targetObject instanceof HoyoverseGameProfile) {
            $gameId = $targetObject->getGameId();
        } elseif (is_array($data) && isset($data['game_id'])) {
            $gameId = (int) $data['game_id'];
        }

        if ($gameId !== null) {
            // Apply strict whitelist: only 'active', 'notificationPlatforms', and implemented interface properties
            $context[AbstractNormalizer::ATTRIBUTES] = $this->getAllowedFields($gameId);
        }

        return $this->objectNormalizer->denormalize($data, $type, $format, $context);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === HoyoverseGameProfile::class;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            HoyoverseGameProfile::class => false,
        ];
    }
}
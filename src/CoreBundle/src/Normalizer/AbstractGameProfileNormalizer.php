<?php

namespace App\CoreBundle\Normalizer;

use InvalidArgumentException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\ServiceCollectionInterface;

abstract class AbstractGameProfileNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * Base fields that can always be modified by the user regardless of the game.
     */
    public const array BASE_WRITABLE_FIELDS = [
        'active',
        'notificationPlatforms',
    ];

    public function __construct(
        protected readonly NormalizerInterface&DenormalizerInterface $objectNormalizer,
        protected readonly ServiceCollectionInterface $locatorByGameId,
    ) {
    }

    /**
     * @return class-string The entity class supported by this normalizer.
     */
    abstract protected function getSupportedClass(): string;

    /**
     * @return array<class-string, list<string>> Mapping of interface class to field names.
     */
    abstract protected function getConditionalSettings(): array;

    /**
     * @return array<string, string> Mapping of parsed timezone to real IANA timezone.
     */
    protected function getTimezoneMap(): array
    {
        return [];
    }

    /**
     * Base fields that can always be modified by the user regardless of the game.
     *
     * @return list<string>
     */
    protected function getBaseWritableFields(): array
    {
        return static::BASE_WRITABLE_FIELDS;
    }

    /**
     * Computes the camelCase fields allowed for modification (whitelist) for a specific gameId.
     *
     * @return list<string>
     */
    protected function getAllowedFields(int $gameId): array
    {
        $allowed = $this->getBaseWritableFields();

        if (!$this->locatorByGameId->has($gameId)) {
            return $allowed;
        }

        $gameService = $this->locatorByGameId->get($gameId);
        foreach ($this->getConditionalSettings() as $interfaceClass => $fields) {
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
     *
     * @return list<string>
     */
    protected function getIgnoredFields(int $gameId): array
    {
        $ignored = [];

        if (!$this->locatorByGameId->has($gameId)) {
            foreach ($this->getConditionalSettings() as $fields) {
                foreach ($fields as $field) {
                    $ignored[] = $field;
                }
            }
            return $ignored;
        }

        $gameService = $this->locatorByGameId->get($gameId);
        foreach ($this->getConditionalSettings() as $interfaceClass => $fields) {
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
        $supportedClass = $this->getSupportedClass();
        if (!$data instanceof $supportedClass) {
            throw new InvalidArgumentException(sprintf("The object must be an instance of '%s'.", $supportedClass));
        }

        /** @var mixed $data */
        $gameId = (int) $data->getGameId();

        // Scope the serializer metadata cache_key per gameId so profiles belonging to
        // different games within the same collection do not share the same cached attributes.
        if (isset($context['cache_key'])) {
            $context['cache_key'] .= '-game-' . $gameId;
        }

        // Apply ignored fields dynamically based on the game's implemented interfaces
        foreach ($this->getIgnoredFields($gameId) as $field) {
            $context[AbstractNormalizer::IGNORED_ATTRIBUTES][] = $field;
        }

        $result = $this->objectNormalizer->normalize($data, $format, $context);
        if (is_array($result) && method_exists($data, 'getParsedTimezone')) {
            $timezoneMap = $this->getTimezoneMap();
            $parsedTz = $data->getParsedTimezone();
            $result['timezone'] = $timezoneMap[$parsedTz] ?? $parsedTz;
        }

        return $result;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        $supportedClass = $this->getSupportedClass();
        return $data instanceof $supportedClass;
    }

    /* -------------------------------------------------------------------------
     * DENORMALIZER (Array/JSON -> Entity)
     * ------------------------------------------------------------------------- */

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $targetObject = $context[AbstractNormalizer::OBJECT_TO_POPULATE] ?? null;

        $supportedClass = $this->getSupportedClass();
        $gameId = null;
        if ($targetObject instanceof $supportedClass) {
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
        return $type === $this->getSupportedClass();
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            $this->getSupportedClass() => false,
        ];
    }
}

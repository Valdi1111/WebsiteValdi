<?php

namespace App\CoreBundle\Normalizer;

use Doctrine\Common\Collections\Collection;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsTaggedItem('serializer.normalizer')]
class CollectionCountNormalizer implements NormalizerInterface
{
    public const string SERIALIZE = 'serialize_count';

    /**
     * @inheritDoc
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): int
    {
        if (!$object instanceof Collection) {
            throw new InvalidArgumentException("The object must implement the 'Collection' class.");
        }
        return $object->count();
    }

    /**
     * @inheritDoc
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Collection && ($context[self::SERIALIZE] ?? false);
    }

    /**
     * @inheritDoc
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            Collection::class => true,
        ];
    }
}
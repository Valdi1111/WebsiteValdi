<?php

namespace App\BooksBundle\Normalizer;

use App\BooksBundle\Entity\BookCache;
use InvalidArgumentException;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsTaggedItem('serializer.normalizer')]
class BookCacheNormalizer implements NormalizerInterface
{
    const string COVER_FILTER = 'cover_filter';

    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private readonly NormalizerInterface $normalizer,
        private readonly CacheManager        $cacheManager)
    {
    }

    /**
     * @inheritDoc
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        if (!$object instanceof BookCache) {
            throw new InvalidArgumentException("The object must implement the 'AbstractBook' class.");
        }
        $json = $this->normalizer->normalize($object, $format, $context);
        if(isset($context[self::COVER_FILTER])) {
            $json['cover'] = $object->generateCoverThumbnail($this->cacheManager, $context[self::COVER_FILTER]);
        }
        return $json;
    }

    /**
     * @inheritDoc
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof BookCache;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            BookCache::class => true,
        ];
    }
}
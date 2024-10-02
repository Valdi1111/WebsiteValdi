<?php

namespace App\BooksBundle\Normalizer;

use App\BooksBundle\Entity\AbstractBook;
use InvalidArgumentException;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsTaggedItem('serializer.normalizer')]
class BookNormalizer implements NormalizerInterface
{
    const string COVER_FILTER = 'cover_filter';
    const string ONLY_METADATA = 'only_metadata';

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
        if (!$object instanceof AbstractBook) {
            throw new InvalidArgumentException("The object must implement the 'AbstractBook' class.");
        }
        if($context[self::ONLY_METADATA] ?? false) {
            $json = $this->normalizer->normalize($object->getMetadata(), $format, $context);
            $json['cover'] = $object->generateCoverThumbnail($this->cacheManager, $context[self::COVER_FILTER] ?? 'books_cover');
            return $json;
        }
        $json = $this->normalizer->normalize($object, $format, $context);
        $json['book_cache']['cover'] = $object->generateCoverThumbnail($this->cacheManager, $context[self::COVER_FILTER] ?? 'books_thumb');
        return $json;
    }

    /**
     * @inheritDoc
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof AbstractBook;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            AbstractBook::class => true,
        ];
    }
}
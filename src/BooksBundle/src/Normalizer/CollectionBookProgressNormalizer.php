<?php

namespace App\BooksBundle\Normalizer;

use App\BooksBundle\Entity\BookProgress;
use App\CoreBundle\Entity\User;
use Doctrine\Common\Collections\Collection;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsTaggedItem('serializer.normalizer')]
class CollectionBookProgressNormalizer implements NormalizerInterface
{
    public const string SERIALIZE = 'serialize_book_progress';

    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private readonly NormalizerInterface $normalizer,
        private readonly Security            $security)
    {
    }

    /**
     * @inheritDoc
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Collection) {
            throw new InvalidArgumentException("The object must implement the 'Collection' class.");
        }
        /** @var User $user */
        $user = $this->security->getUser();
        $progress = $data->get($user->getId());
        if (!$progress) {
            $progress = new BookProgress();
        }
        return $this->normalizer->normalize($progress, $format, $context);
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
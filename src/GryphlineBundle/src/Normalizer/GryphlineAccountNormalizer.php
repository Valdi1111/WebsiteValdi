<?php

namespace App\GryphlineBundle\Normalizer;

use App\GryphlineBundle\Entity\GryphlineAccount;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class GryphlineAccountNormalizer implements NormalizerInterface
{
    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private readonly NormalizerInterface $objectNormalizer,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        if (!$data instanceof GryphlineAccount) {
            throw new InvalidArgumentException(sprintf("The object must be an instance of '%s'.", GryphlineAccount::class));
        }

        $json = $this->objectNormalizer->normalize($data, $format, $context);
        $json['is_session_valid'] = $data->isSessionValid();
        $json['has_token'] = !empty($data->getAccountToken());

        return $json;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof GryphlineAccount;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            GryphlineAccount::class => false,
        ];
    }
}

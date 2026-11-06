<?php

namespace App\HoyoverseBundle\Normalizer;

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class DateTimeArrayDenormalizer implements DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): \DateTimeInterface
    {
        $dt = new \DateTimeImmutable()
            ->setDate((int) $data['year'], (int) $data['month'], (int) $data['day'])
            ->setTime((int) $data['hour'], (int) $data['minute'], (int) ($data['second'] ?? 0));

        // Restituisce DateTime o DateTimeImmutable a seconda del type richiesto
        return is_a($type, \DateTime::class, true) ? \DateTime::createFromImmutable($dt) : $dt;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_array($data)
            && is_a($type, \DateTimeInterface::class, true)
            && isset($data['year'], $data['month'], $data['day'], $data['hour'], $data['minute']);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            \DateTimeInterface::class => false,
            \DateTimeImmutable::class => false,
            \DateTime::class => false,
        ];
    }
}

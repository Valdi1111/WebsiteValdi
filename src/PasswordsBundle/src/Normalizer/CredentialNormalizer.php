<?php

namespace App\PasswordsBundle\Normalizer;

use App\PasswordsBundle\Entity\Credential;
use App\PasswordsBundle\Service\EncryptionService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AsTaggedItem('serializer.normalizer')]
readonly class CredentialNormalizer implements NormalizerInterface, DenormalizerInterface
{

    public function __construct(
        private EncryptionService     $encryptionService,
        #[Autowire(service: 'serializer.normalizer.object')]
        private NormalizerInterface   $normalizer,
        #[Autowire(service: 'serializer.normalizer.object')]
        private DenormalizerInterface $denormalizer
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Credential) {
            throw new InvalidArgumentException("The object must implement the 'Credential' class.");
        }
        $json = $this->normalizer->normalize($data, $format, $context);
        if (isset($json['password']) && $json['password'] !== '') {
            $json['password'] = $this->encryptionService->decrypt($data->getPassword());
        }
        return $json;
    }

    /**
     * @inheritDoc
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Credential
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException("The data must be an array.");
        }
        /** @var Credential $credential */
        $credential = $this->denormalizer->denormalize($data, $type, $format, array_merge($context, ['ignored_attributes' => ['id']]));
        if (array_key_exists('password', $data)) {
            if (($data['password'] ?? '') !== '') {
                $credential->setPassword($this->encryptionService->encrypt($data['password']));
            } else {
                $credential->setPassword($data['password']);
            }
        }
        return $credential;
    }

    /**
     * @inheritDoc
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Credential;
    }

    /**
     * @inheritDoc
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_array($data) && $type === Credential::class;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            Credential::class => true,
        ];
    }
}
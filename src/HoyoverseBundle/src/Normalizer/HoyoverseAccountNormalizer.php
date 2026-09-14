<?php

namespace App\HoyoverseBundle\Normalizer;

use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class HoyoverseAccountNormalizer implements NormalizerInterface
{

    public function __construct(
        #[Autowire(service: 'serializer.normalizer.object')]
        private readonly NormalizerInterface $objectNormalizer,
        #[Autowire(lazy: true)]
        private readonly HoyolabCookieUtilsService $cookieUtils,
    ) {
    }


    /* -------------------------------------------------------------------------
     * NORMALIZER (Entity -> Array/JSON)
     * ------------------------------------------------------------------------- */

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        if (!$data instanceof HoyoverseAccount) {
            throw new InvalidArgumentException(sprintf("The object must be an instance of '%s'.", HoyoverseAccount::class));
        }

        $parsedCookie = $this->cookieUtils->parseCookie($data->getCookie());

        $json = $this->objectNormalizer->normalize($data, $format, $context);
        $json["can_redeem_codes"] = $parsedCookie->canRedeemCodes();
        return $json;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof HoyoverseAccount;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            HoyoverseAccount::class => false,
        ];
    }
}
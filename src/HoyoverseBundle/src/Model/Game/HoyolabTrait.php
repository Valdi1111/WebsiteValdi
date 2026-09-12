<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Service\HoyolabCookieUtilsService;
use App\HoyoverseBundle\Service\HoyolabUtilsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\Attribute\Required;

trait HoyolabTrait
{
    private ?HttpClientInterface $hoyolabClient = null;
    private ?HoyolabCookieUtilsService $cookieUtils = null;
    private ?HoyolabUtilsService $hoyolabUtils = null;
    private ?DenormalizerInterface $denormalizer = null;

    public function __construct(
        private readonly LoggerInterface $hoyoverseLogger
    )
    {
    }

    public function getLogger(): LoggerInterface
    {
        return $this->hoyoverseLogger;
    }

    public function getHoyolabClient(): ?HttpClientInterface
    {
        return $this->hoyolabClient;
    }

    #[Required]
    public function setHoyolabClient(HttpClientInterface $hoyoverseHoyolabClient): void
    {
        $this->hoyolabClient = $hoyoverseHoyolabClient;
    }

    public function getCookieUtils(): ?HoyolabCookieUtilsService
    {
        return $this->cookieUtils;
    }

    #[Required]
    public function setCookieUtils(HoyolabCookieUtilsService $cookieUtils): void
    {
        $this->cookieUtils = $cookieUtils;
    }

    public function getHoyolabUtils(): ?HoyolabUtilsService
    {
        return $this->hoyolabUtils;
    }

    #[Required]
    public function setHoyolabUtils(HoyolabUtilsService $utils): void
    {
        $this->hoyolabUtils = $utils;
    }

    public function getDenormalizer(): ?DenormalizerInterface
    {
        return $this->denormalizer;
    }

    #[Required]
    public function setDenormalizer(DenormalizerInterface $denormalizer): void
    {
        $this->denormalizer = $denormalizer;
    }

}
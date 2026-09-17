<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Service\HoyolabUtilsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

interface GameInterface
{
    public static function getType();

    public static function getGameBiz(): string;

    public static function getGameId(): int;

    public function getGameName(): string;

    public function getAuthor(): string;

    public function getLogger(): LoggerInterface;

    public function getHoyolabClient(): ?HttpClientInterface;

    public function getDenormalizer(): ?DenormalizerInterface;

    public function getHoyolabUtils(): ?HoyolabUtilsService;
}
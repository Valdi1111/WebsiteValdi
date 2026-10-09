<?php

namespace App\HoyoverseBundle\Model\Game;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

interface HasCodeRedemptionInterface
{
    public function getRedeemableCodesClient(): ?HttpClientInterface;

    public function getCodeRedemptionManualReason(): string;

    public function getUrlFetchRedeemableCodes(): string;

    public function fetchRedeemableCodes(): array;
}

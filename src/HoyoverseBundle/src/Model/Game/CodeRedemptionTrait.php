<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\RetrieveRedeemableCodesException;
use App\HoyoverseBundle\Model\RedeemableCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @mixin GameInterface
 * @mixin HasCodeRedemptionInterface
 */
trait CodeRedemptionTrait
{
    private ?HttpClientInterface $redeemableCodesClient = null;

    public function getRedeemableCodesClient(): ?HttpClientInterface
    {
        return $this->redeemableCodesClient;
    }

    #[Required]
    public function setRedeemableCodesClient(HttpClientInterface $hoyoverseRedeemableCodesClient): void
    {
        $this->redeemableCodesClient = $hoyoverseRedeemableCodesClient;
    }

    public function getCodeRedemptionManualReason(): string
    {
        return "Redeem this code from within the game client.";
    }

    /**
     * @return RedeemableCode[]
     */
    public function fetchRedeemableCodes(): array
    {
        $body = $this->executeRequest(
            client: $this->getRedeemableCodesClient(),
            method: Request::METHOD_GET,
            url: $this->getUrlFetchRedeemableCodes(),
            options: [],
            exceptionClass: RetrieveRedeemableCodesException::class
        );

        $active = $body['active'] ?? null;
        if (!is_array($active)) {
            throw new RetrieveRedeemableCodesException('Redeemable codes returned malformed data')
                ->setHoyolabBody($body);
        }

        return $this->getDenormalizer()->denormalize(
            $active,
            RedeemableCode::class . '[]'
        );
    }

}
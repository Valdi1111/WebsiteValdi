<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\CodeRedeemFailedException;
use App\HoyoverseBundle\Exception\RetrieveRedeemableCodesException;
use App\HoyoverseBundle\Model\RedeemableCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
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
        try {
            $response = $this->getRedeemableCodesClient()->request(Request::METHOD_GET, $this->getUrlFetchRedeemableCodes());

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to retrieve redeemable codes", [
                    'status' => $statusCode,
                    'body'   => $body,
                ]);

                throw new RetrieveRedeemableCodesException("Failed to retrieve redeemable codes")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $active = $body['active'] ?? null;
            if (!is_array($active)) {
                $this->getLogger()->error("Redeemable codes returned malformed data", [
                    'status' => $statusCode,
                    'body'   => $body,
                ]);

                throw new RetrieveRedeemableCodesException("Redeemable codes returned malformed data")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            return $this->getDenormalizer()->denormalize($active, RedeemableCode::class . '[]');

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during redeemable codes retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new CodeRedeemFailedException("Exception during redeemable codes retrieval: {$e->getMessage()}");
        }
    }

}
<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\CodeRedeemFailedException;
use App\HoyoverseBundle\Model\RedeemableCode;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * @mixin GameInterface
 * @mixin HasAutoCodeRedemptionInterface
 */
trait AutoCodeRedemptionTrait
{
    use CodeRedemptionTrait;

    public function redeemCode(RuntimeAccountData $account, RedeemableCode $redeemableCode): void
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_POST, $this->getUrlCodeRedemption(), [
                'query' => [
                    'uid' => $account->getGameProfile()->getGameUid(),
                    'region' => $account->getGameProfile()->getRegion(),
                    'lang' => 'en',
                    'cdkey' => $redeemableCode->getCode(),
                    'game_biz' => $account->getGameProfile()->getGameBiz(),
                    't' => new \DateTime()->getTimestamp(),
                ],
                'headers' => [
                    'Cookie' => (string) $account->getParsedCookie(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== 200) {
                $this->getLogger()->error("Failed to redeem code", [
                    'status' => $statusCode,
                    'body'   => $body,
                ]);

                throw new CodeRedeemFailedException("Failed to redeem code")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0) {
                $this->getLogger()->error("Redeem code returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new CodeRedeemFailedException("Redeem code returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

            $this->getLogger()->info("({$account->getGameProfile()->getGameUid()}) {$account->getGameProfile()->getNickname()} redeemed code: {$redeemableCode->getCode()}");

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during code redeem", [
                'error' => $e->getMessage(),
            ]);

            throw new CodeRedeemFailedException("Exception during code redeem: {$e->getMessage()}");
        }
    }

}
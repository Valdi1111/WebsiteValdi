<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\NoAwardsDataException;
use App\HoyoverseBundle\Exception\RetrieveAwardsDataException;
use App\HoyoverseBundle\Exception\RetrieveSignInfoException;
use App\HoyoverseBundle\Exception\SignInFailedException;
use App\HoyoverseBundle\Model\AwardData;
use App\HoyoverseBundle\Model\CheckInResult;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use App\HoyoverseBundle\Model\SignInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * @mixin GameInterface
 * @mixin HasHoyolabCheckInInterface
 */
trait HoyolabCheckInTrait
{

    public function checkIn(RuntimeAccountData $account): CheckInResult
    {
        $signInfo = $this->getSignInfo($account);
        /** @var AwardData[] $awardsData */
        $awardsData = $this->getAwardsData($account);

        if ($signInfo->isSign()) {
            $this->getLogger()->info("{$account->getGameProfile()->getNickname()} already signed in today");

            return new CheckInResult()
                ->setAlreadySignedIn(true)
                ->setResult($this->getCheckInSignedMessage())
                ->setTotalSignDay($signInfo->getTotalSignDay())
                ->setAwardData($awardsData[$signInfo->getTotalSignDay() - 1]);
        }

        $this->signIn($account);
        $awardData = $awardsData[$signInfo->getTotalSignDay()];

        $this->getLogger()->info("({$account->getGameProfile()->getGameUid()}) {$account->getGameProfile()->getNickname()} Today's Reward: {$awardData->getName()} x{$awardData->getCnt()}");

        return new CheckInResult()
            ->setResult($this->getCheckInSuccessMessage())
            ->setTotalSignDay($signInfo->getTotalSignDay() + 1)
            ->setAwardData($awardData);
    }

    public function signIn(RuntimeAccountData $account): void
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_POST, $this->getUrlSign(), [
                'query' => [
                    'act_id' => $this->getActId(),
                ],
                'headers' => [
                    'Cookie' => (string) $account->getParsedCookie(),
                    'x-rpc-signgame' => $this->getSignGame(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to sign in", [
                    'status' => $statusCode,
                    'body'   => $body,
                ]);

                throw new SignInFailedException("Failed to sign in")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0) {
                $this->getLogger()->error("Sign in returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new SignInFailedException("Sign in returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during sign in", [
                'error' => $e->getMessage(),
            ]);

            throw new SignInFailedException("Exception during sign in: {$e->getMessage()}");
        }
    }

    public function getSignInfo(RuntimeAccountData $account): SignInfo
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlInfo(), [
                'query' => [
                    'act_id' => $this->getActId(),
                ],
                'headers' => [
                    'Cookie' => (string) $account->getParsedCookie(),
                    'x-rpc-signgame' => $this->getSignGame(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to retrieve sign info", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new RetrieveSignInfoException("Failed to retrieve sign info")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0) {
                $this->getLogger()->error("Sign info returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new RetrieveSignInfoException("Sign info returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

            $data = $body['data'] ?? [];

            return $this->getDenormalizer()->denormalize($data, SignInfo::class);

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during sign info retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveSignInfoException("Exception during sign info retrieval: {$e->getMessage()}");
        }
    }

    /**
     * @inheritDoc
     */
    public function getAwardsData(RuntimeAccountData $account): array
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlHome(), [
                'query' => [
                    'act_id' => $this->getActId(),
                ],
                'headers' => [
                    'Cookie' => (string) $account->getParsedCookie(),
                    'x-rpc-signgame' => $this->getSignGame(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to retrieve awards data", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new RetrieveAwardsDataException("Failed to retrieve awards data")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0) {
                $this->getLogger()->error("Awards data returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new RetrieveAwardsDataException("Awards data returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

            $data = $body['data'] ?? [];
            $awards = $data['awards'] ?? [];

            if (empty($awards)) {
                $this->getLogger()->error("No awards data available", [
                    'body' => $body,
                ]);

                throw new NoAwardsDataException()
                    ->setHoyolabBody($body);
            }

            return $this->getDenormalizer()->denormalize($awards, AwardData::class . '[]');

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during awards data retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveAwardsDataException("Exception during awards data retrieval: {$e->getMessage()}");
        }
    }

    abstract protected function getCheckInSuccessMessage(): string;

    abstract protected function getCheckInSignedMessage(): string;

}
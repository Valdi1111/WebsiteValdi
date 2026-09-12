<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\RetrieveDiaryDataException;
use App\HoyoverseBundle\Model\GameRecordCard;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * @mixin GameInterface
 * @mixin HasDiaryInterface
 */
trait DiaryTrait
{

    public function getDiaryInfo(RuntimeAccountData $account): array
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlDiaryInfo(), [
                'query' => [
                    'uid' => $account->getGameProfile()->getGameUid(),
                    'region' => $account->getGameProfile()->getRegion(),
                    // TODO month?
//                    'month' => $this->getDiaryMonth(...),
                ],
                'headers' => [
                    'Cookie' => (string) $account->getParsedCookie(),
                    'DS' => $this->getHoyolabUtils()->generateDS(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to retrieve diary", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new RetrieveDiaryDataException("Failed to retrieve diary")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0) {
                $this->getLogger()->error("Diary returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new RetrieveDiaryDataException("Diary returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

            $data = $body['data'] ?? [];

            return $this->getDenormalizer()->denormalize($game, GameRecordCard::class);

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during diary retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveDiaryDataException("Exception during diary retrieval: {$e->getMessage()}");
        }
    }

    public function getDiaryDetail(RuntimeAccountData $account): array
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlDiaryDetail(), [
                'query' => [
                    'uid' => $account->getGameProfile()->getGameUid(),
                    'region' => $account->getGameProfile()->getRegion(),
                    // TODO month?
//                    'month' => $this->getDiaryMonth(...),
                    'type' => 1,
                    'current_page' => 1,
                    'page_size' => 100,
                ],
                'headers' => [
                    'Cookie' => (string) $account->getParsedCookie(),
                    'DS' => $this->getHoyolabUtils()->generateDS(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to retrieve diary", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new RetrieveDiaryDataException("Failed to retrieve diary")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0) {
                if ($retcode === -501000 /*&& retry count < max retries*/) { // TODO why?
                    // sleep retry delay
                    // retry
                }
                $this->getLogger()->error("Diary returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new RetrieveDiaryDataException("Diary returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

            $data = $body['data'] ?? [];

            return $this->getDenormalizer()->denormalize($game, GameRecordCard::class);

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during diary retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveDiaryDataException("Exception during diary retrieval: {$e->getMessage()}");
        }
    }

}
<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Entity\HoyoverseDiaryEntry;
use App\HoyoverseBundle\Exception\RetrieveDiaryDataException;
use App\HoyoverseBundle\Model\Diary\GameDiaryCurrency;
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

    public function getDiaryInfo(RuntimeAccountData $account, string $month): array
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlDiaryInfo(), [
                'query' => [
                    'uid' => $account->getGameProfile()->getGameUid(),
                    'region' => $account->getGameProfile()->getRegion(),
                    'month' => $month,
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

            // TODO
//            return $this->getDenormalizer()->denormalize($game, GameRecordCard::class);

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during diary retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveDiaryDataException("Exception during diary retrieval: {$e->getMessage()}");
        }
    }

    public function getDiaryItems(RuntimeAccountData $account, GameDiaryCurrency $currency, string $month, int $currentPage = 1, int $pageSize = 100): array
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlDiaryDetail(), [
                'query' => [
                    'uid' => $account->getGameProfile()->getGameUid(),
                    'region' => $account->getGameProfile()->getRegion(),
                    'month' => $month,
                    'type' => $currency->getApiType(),
                    'current_page' => $currentPage,
                    'page_size' => $pageSize,
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
            $list = $data['list'] ?? [];

            return $this->getDenormalizer()->denormalize($list, $this->getDiaryItemClass() . '[]');

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during diary retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveDiaryDataException("Exception during diary retrieval: {$e->getMessage()}");
        }
    }

    public function getDiaryEntries(RuntimeAccountData $account, GameDiaryCurrency $currency, string $month, string $period, int $currentPage = 1, int $pageSize = 100): array
    {
        $diaryEntries = [];
        $diaryItems = $this->getDiaryItems($account, $currency, $month, $currentPage, $pageSize);
        foreach ($diaryItems as $diaryItem) {
            $diaryEntry = $this->getObjectMapper()->map($diaryItem, HoyoverseDiaryEntry::class)
                ->setCurrency($currency)
                ->setPeriod($period);
            $diaryEntries[] = $diaryEntry;
        }
        return $diaryEntries;
    }

}
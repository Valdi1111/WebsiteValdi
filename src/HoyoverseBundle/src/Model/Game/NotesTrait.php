<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\RetrieveNotesDataException;
use App\HoyoverseBundle\Model\Notes\GameNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * @mixin GameInterface
 * @mixin HasNotesInterface
 */
trait NotesTrait
{

    public function getNotes(RuntimeAccountData $account): GameNotes
    {
        try {
            $response = $this->getHoyolabClient()->request(Request::METHOD_GET, $this->getUrlNotes(), [
                'query' => [
                    'role_id' => $account->getGameProfile()->getGameUid(),
                    'server' => $account->getGameProfile()->getRegion(),
                ],
                'headers' => [
                    // TODO solo ltoken_v2, ltmid_v2, ltuid_v2
                    'Cookie' => (string) $account->getParsedCookie(),
                    'DS' => $this->getHoyolabUtils()->generateDS(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($statusCode !== Response::HTTP_OK) {
                $this->getLogger()->error("Failed to retrieve notes", [
                    'status' => $statusCode,
                    'body' => $body,
                ]);

                throw new RetrieveNotesDataException("Failed to retrieve notes")
                    ->setHoyolabStatusCode($statusCode)
                    ->setHoyolabBody($body);
            }

            $retcode = $body['retcode'] ?? null;
            if ($retcode !== 0 && $retcode !== -501000) { // TODO why?
                $this->getLogger()->error("Notes returned non-zero retcode", [
                    'retcode' => $retcode,
                    'message' => $body['message'] ?? null,
                    'body' => $body,
                ]);

                throw new RetrieveNotesDataException("Notes returned non-zero retcode")
                    ->setHoyolabRetcode($retcode)
                    ->setHoyolabMessage($body['message'] ?? null)
                    ->setHoyolabBody($body);
            }

            $data = $body['data'] ?? [];

            return $this->getDenormalizer()->denormalize($data, $this->getNotesClass());

        } catch (ExceptionInterface $e) {
            $this->getLogger()->error("Exception during notes retrieval", [
                'error' => $e->getMessage(),
            ]);

            throw new RetrieveNotesDataException("Exception during notes retrieval: {$e->getMessage()}");
        }
    }

}
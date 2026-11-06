<?php

namespace App\GryphlineBundle\Model\Game;

use App\CoreBundle\Model\Game\HasNotesInterface;
use App\CoreBundle\Model\GameNotes\GameNotes;
use App\GryphlineBundle\Exception\SkportApiException;
use App\CoreBundle\Model\Game\RuntimeAccountDataInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @mixin GryphlineGameInterface
 * @mixin HasNotesInterface
 */
trait NotesTrait
{
    public function getNotes(RuntimeAccountDataInterface $account, bool $retryOn401 = true): GameNotes
    {
        $profile = $account->getGameProfile();
        $creds = $this->ensureCredentials($account);

        $path = $this->getUrlNotes();
        $timestamp = (string) time();
        $sign = $this->getSignerService()->generateSignV2($path, $timestamp, $creds->getSalt());

        $headers = [
            'cred' => $creds->getCred(),
            'timestamp' => $timestamp,
            'platform' => '3',
            'vname' => '1.0.0',
            'sk-language' => 'en',
            'sk-game-role' => $profile->getSkGameRole(),
            'sign' => $sign,
        ];

        $response = $this->getSkportClient()->request(Request::METHOD_GET, $path, [
            'headers' => $headers,
        ]);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED && $retryOn401) {
            $this->getLogger()->warning('SKPort session expired during notes request, forcing OAuth refresh...');
            $this->ensureCredentials($account, force: true);

            return $this->getNotes($account, retryOn401: false);
        }

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            throw (new SkportApiException('Notes HTTP error: ' . $response->getStatusCode()))
                ->setApiStatusCode($response->getStatusCode());
        }

        $body = $response->toArray(false);
        if (($body['code'] ?? -1) !== 0) {
            throw (new SkportApiException('Notes request failed: ' . ($body['message'] ?? 'Error')))
                ->setApiCode($body['code'] ?? null)
                ->setResponseBody($body);
        }

        return $this->getDenormalizer()->denormalize(
            $body['data']['detail'] ?? $body['data'] ?? [],
            $this->getNotesClass()
        );
    }
}

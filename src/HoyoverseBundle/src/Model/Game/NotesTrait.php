<?php

namespace App\HoyoverseBundle\Model\Game;

use App\HoyoverseBundle\Exception\RetrieveNotesDataException;
use App\HoyoverseBundle\Model\Notes\GameNotes;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasNotesInterface
 */
trait NotesTrait
{

    public function getNotes(RuntimeAccountData $account): GameNotes
    {
        $profile = $account->getGameProfile();

        $body = $this->requestHoyolab(
            method: Request::METHOD_GET,
            url: $this->getUrlNotes(),
            auth: $account,
            query: [
                'role_id' => $profile->getGameUid(),
                'server' => $profile->getRegion(),
            ],
            exceptionClass: RetrieveNotesDataException::class,
            extraHeaders: ['DS' => $this->getHoyolabUtils()->generateDS()],
            allowedRetcodes: [0, -501000] // Tolerates -501000 without treating it as an unrecoverable failure
        );

        return $this->getDenormalizer()->denormalize(
            $body['data'] ?? [],
            $this->getNotesClass()
        );
    }

}
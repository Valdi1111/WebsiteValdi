<?php

namespace App\HoyoverseBundle\Model\Game;

use App\CoreBundle\Model\Game\HasNotesInterface;
use App\CoreBundle\Model\Game\RuntimeAccountDataInterface;
use App\CoreBundle\Model\GameNotes\GameNotes;
use App\HoyoverseBundle\Exception\RetrieveNotesDataException;
use Symfony\Component\HttpFoundation\Request;

/**
 * @mixin GameInterface
 * @mixin HasNotesInterface
 */
trait NotesTrait
{

    public function getNotes(RuntimeAccountDataInterface $account): GameNotes
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

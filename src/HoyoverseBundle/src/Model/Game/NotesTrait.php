<?php

namespace App\HoyoverseBundle\Model\Game;

use App\GamesBundle\Model\Game\CachedNotesTrait;
use App\GamesBundle\Model\Game\HasNotesInterface;
use App\GamesBundle\Model\Game\RuntimeAccountDataInterface;
use App\GamesBundle\Model\GameNotes\GameNotes;
use App\HoyoverseBundle\Exception\RetrieveNotesDataException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @mixin GameInterface
 * @mixin HasNotesInterface
 */
trait NotesTrait
{
    use CachedNotesTrait;

    #[Required]
    public function setNotesCache(
        #[Target('hoyoverse.notes.cache')]
        CacheInterface $cache
    ): void
    {
        $this->notesCache = $cache;
    }

    public function getNotes(RuntimeAccountDataInterface $account, bool $fresh = false): GameNotes
    {
        $data = $this->getCachedNotesData($account, $fresh, fn (): array => $this->fetchNotesData($account));

        return $this->getDenormalizer()->denormalize($data, $this->getNotesClass());
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool} the notes payload, and whether it may be cached
     */
    private function fetchNotesData(RuntimeAccountDataInterface $account): array
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

        $data = $body['data'] ?? null;

        // A -501000 answer is used as it is but not cached, or a partial payload would stick for the whole TTL
        return [is_array($data) ? $data : [], ($body['retcode'] ?? null) === 0 && is_array($data)];
    }

}
